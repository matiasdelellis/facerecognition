<?php
/**
 * @copyright Copyright (c) 2021 Ming Tsang <nkming2@gmail.com>
 * @copyright Copyright (c) 2022 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Ming Tsang <nkming2@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\Files\File;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\ApiController as NCApiController;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\ManualRegion;
use OCA\FaceRecognition\Db\ManualRegionMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\FaceParticipation;
use OCA\FaceRecognition\Helper\ManualFaceDetector;

use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

use Psr\Log\LoggerInterface;

class ApiController extends NcApiController {

	/** How many faces one request may delete, ignore or stop ignoring */
	private const MAX_FACES_PER_REQUEST = 500;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

/** @var ClusterMapper */
	private $clusterMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var UrlService */
	private $urlService;

	/** @var ManualRegionMapper */
	private $manualRegionMapper;

	/** @var ManualFaceService */
	private $manualFaceService;

	/** @var LoggerInterface */
	private $logger;

	/** @var string */
	private $userId;

	public function __construct(
		$AppName,
		IRequest        $request,
		FaceMapper      $faceMapper,
		ImageMapper     $imageMapper,
ClusterMapper   $clusterMapper,
	                            PersonMapper    $personmapper,
		SettingsService $settingsService,
		UrlService      $urlService,
		ManualRegionMapper $manualRegionMapper,
		LoggerInterface $logger,
		ManualFaceService $manualFaceService,
		$UserId)
	{
		parent::__construct($AppName, $request);

		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
$this->clusterMapper  = $clusterMapper;
		$this->personMapper    = $personmapper;
		$this->settingsService = $settingsService;
		$this->urlService      = $urlService;
		$this->manualRegionMapper = $manualRegionMapper;
		$this->logger          = $logger;
		$this->manualFaceService = $manualFaceService;
		$this->userId          = $UserId;
	}

	/**
	 * API V1
	 */

	/**
	 * Get all named persons
	 *
	 * - Endpoint: /persons
	 * - Method: GET
	 * - Response: Array of persons
	 * 		- Person:
	 * 			- name: Name of the person
	 * 			- thumbFaceId: Face representing this person
	 * 			- count: Number of images associated to this person
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function getPersons(): JSONResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new JSONResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findAll($this->userId, $modelId);
		foreach ($persons as $person) {
			$facesCount = 0;
			$thumbFaceId = null;
			foreach ($this->clustersOfName($person->getName()) as $cluster) {
				$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId);
				if (is_null($thumbFaceId) && !empty($clusterFaces)) {
					$thumbFaceId = $clusterFaces[0]->getId();
				}
				$facesCount += count($clusterFaces);
			}

			$respPerson = [];
			$respPerson['name'] = $person->getName();
			$respPerson['thumbFaceId'] = $thumbFaceId;
			$respPerson['count'] = $facesCount;

			$resp[] = $respPerson;
		}

		return new JSONResponse($resp);
	}

	/**
	 * Get all faces associated to a person
	 *
	 * - Endpoint: /person/<name>/faces
	 * - Method: GET
	 * - URL Arguments: name - (string) name of the person
	 * - Response: Array of faces
	 * 		- Face:
	 * 			- id: Face ID
	 * 			- fileId: The file where this face was found
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function getFacesByPerson(string $name): JSONResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new JSONResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->clustersOfName($name);
		foreach ($clusters as $cluster) {
			$faces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId);
			foreach ($faces as $face) {
				$image = $this->imageMapper->find($this->userId, $face->getImage());

				$respFace = [];
				$respFace['id'] = $face->getId();
				$respFace['fileId'] = $image->getFile();

				$resp[] = $respFace;
			}
		}

		return new JSONResponse($resp);
	}

	/**
	 * API V2
	 */

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getPersonsV2($thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$list = [];
		$modelId = $this->settingsService->getCurrentFaceModel();
		$personsNames = $this->personMapper->findAll($this->userId, $modelId);
		foreach ($personsNames as $personNamed) {
			$name = $personNamed->getName();
			$clusterFace = current($this->faceMapper->findFromPerson($this->userId, $name, $modelId, 1));

			$person = [];
			$person['name'] = $name;
			$person['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);
			$person['count'] = $this->imageMapper->countFromPerson($this->userId, $modelId, $name);

			$list[] = $person;
		}

		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getPerson(string $personName, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		if (empty($personName))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$resp = [];
		$resp['name'] = $personName;
		$resp['thumbUrl'] = null;
		$resp['images'] = array();

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusterFace = current($this->faceMapper->findFromPerson($this->userId, $personName, $modelId, 1));
		$resp['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);

		$images = $this->imageMapper->findFromPerson($this->userId, $modelId, $personName);
		foreach ($images as $image) {
			$node = $this->urlService->getFileNode($image->getFile());
			if ($node === null) continue;

			$photo = [];
			$photo['basename'] = $this->urlService->getBasename($node);
			$photo['filename'] = $this->urlService->getFilename($node);
			$photo['mimetype'] = $this->urlService->getMimetype($node);
			$photo['fileUrl']  = $this->urlService->getRedirectToFileUrl($node);
			$photo['thumbUrl'] = $this->urlService->getPreviewUrl($node, 256);

			$resp['images'][] = $photo;
		}

		return new JSONResponse($resp, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function updatePerson(string $personName, $name = null, $visible = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		if (empty($personName))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->clustersOfName($personName);
		if (empty($clusters))
			return new JSONResponse([], Http::STATUS_NOT_FOUND);

		if (!is_null($name)) {
			$person = $this->personMapper->findByName($this->userId, $personName);
			if (!is_null($person)) {
				$this->personMapper->rename($person->getId(), $name);
			}
		}

		// Hiding a cluster also forgets who it was, so the person can be left
		// without clusters and stop being a person.
		if (!is_null($visible)) {
			foreach ($clusters as $cluster) {
				$this->clusterMapper->setVisibility($cluster->getId(), (bool) $visible);
			}
			$this->personMapper->deleteOrphaned($this->userId);
		}

		// FIXME: What should response?
		if (is_null($name) || (!is_null($visible) && !$visible))
			return new JSONResponse([], Http::STATUS_OK);
		else
			return $this->getPerson($name);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function updateCluster(int $clusterId, $name = null, $visible = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$cluster = $this->clusterMapper->find($this->userId, $clusterId);

		if (!is_null($name)) {
			$person = $this->personMapper->findOrCreateByName($this->userId, $name);
			$this->clusterMapper->setPerson($cluster->getId(), $person->getId());
		}

		if (!is_null($visible)) {
			$this->clusterMapper->setVisibility($cluster->getId(), (bool) $visible);
		}

		$this->personMapper->deleteOrphaned($this->userId);
		$cluster = $this->clusterMapper->find($this->userId, $clusterId);

		// FIXME: What should response?
		return new JSONResponse($cluster, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function discoverPerson($minimum_count = NULL, $max_previews = 40, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$discoveries = [];

		$modelId = $this->settingsService->getCurrentFaceModel();
		if (is_null($minimum_count))
			$minimum_count = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->clusterMapper->findUnassigned($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minimum_count)
				continue;

			$clusterFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, $max_previews);

			$faces = [];
			foreach ($clusterFaces as $clusterFace) {
				$image = $this->imageMapper->find($this->userId, $clusterFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);

				$faces[] = $face;
			}

			$discovery = [];
			$discovery['id'] = $cluster->getId();
			$discovery['count'] = $clusterSize;
			$discovery['faces'] = $faces;

			$discoveries[] = $discovery;
		}

		usort($discoveries, function ($a, $b) {
			return $b['count'] <=> $a['count'];
		});

		return new JSONResponse($discoveries, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function autocomplete(string $query, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		if (strlen($query) < 3)
			return new JSONResponse([], Http::STATUS_OK);

		$resp = [];

		$modelId = $this->settingsService->getCurrentFaceModel();
		$persons = $this->personMapper->findLike($this->userId, $modelId, $query);
		foreach ($persons as $person) {
			$name = [];
			$name['name'] = $person->getName();
			$name['value'] = $person->getName();

			$clusterFace = current($this->faceMapper->findFromPerson($this->userId, $person->getName(), $modelId, 1));
			$name['thumbUrl'] = $this->urlService->getThumbUrl($clusterFace->getId(), $thumb_size);

			$resp[] = $name;
		}

		return new JSONResponse($resp, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function detachFace(int $faceId, $name = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		// The face comes from the request: it has to be one of this user's
		// before anything is written, and it has to be in a cluster to be
		// taken out of one.
		$face = $this->faceMapper->find($faceId);
		if (is_null($face))
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		if (is_null($this->imageMapper->find($this->userId, $face->getImage())))
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		if (is_null($face->getCluster()))
			return new JSONResponse(['error' => 'the face is not in a cluster'], Http::STATUS_CONFLICT);

		$personId = null;
		if (!is_null($name) && $name !== '') {
			$personId = $this->personMapper->findOrCreateByName($this->userId, $name)->getId();
		}

		$cluster = $this->clusterMapper->detachFace((int) $face->getCluster(), $faceId, $personId);

		return new JSONResponse($cluster, Http::STATUS_OK);
	}

	/**
	 * Names a face that is in no cluster yet, like a marking saved without a
	 * name: it gets a cluster of its own for the person of that name, as a
	 * marking saved with a name does. A face that is in a cluster is renamed
	 * through the cluster instead (ClusterController::updateName), which is
	 * answered with a conflict here.
	 *
	 * The face is marked manual, so that analyzing the photo again keeps the
	 * name; a marking already is.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function nameFace(int $faceId, ?string $name = null): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$name = trim($name ?? '');
		if ($name === '')
			return new JSONResponse(['error' => 'a name is required'], Http::STATUS_BAD_REQUEST);

		// The face comes from the request: it has to be one of this user's
		// before anything is written.
		$face = $this->faceMapper->find($faceId);
		if (is_null($face))
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		if (is_null($this->imageMapper->find($this->userId, $face->getImage())))
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		if (!is_null($face->getCluster()))
			return new JSONResponse(['error' => 'the face is in a cluster already'], Http::STATUS_CONFLICT);

		$person = $this->personMapper->findOrCreateByName($this->userId, $name);
		$clusterId = $this->clusterMapper->create($this->userId, $this->settingsService->getCurrentFaceModel());
		$this->clusterMapper->setPerson($clusterId, $person->getId());

		// The clustering may have placed the face since it was read above.
		if (!$this->faceMapper->assignClusterIfNone($faceId, $clusterId)) {
			$this->clusterMapper->removeIfEmpty($clusterId);
			$this->personMapper->deleteOrphaned($this->userId);
			return new JSONResponse(['error' => 'the face is in a cluster already'], Http::STATUS_CONFLICT);
		}
		$this->faceMapper->markFaceManual($faceId);

		return new JSONResponse([
			'faceId'    => $faceId,
			'clusterId' => $clusterId,
			'personId'  => $person->getId(),
			'name'      => $person->getName(),
		], Http::STATUS_OK);
	}

	/**
	 * Deletes faces the user put there: markings, and the faces the search of
	 * a region found. A face of the analysis cannot be deleted, since the next
	 * analysis of the photo would find it again; it can be ignored instead.
	 * Nothing is deleted unless every face may be.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @param int[] $faceIds
	 */
	public function deleteFaces(array $faceIds = []): JSONResponse {
		$faces = $this->ownFaces($faceIds);
		if ($faces instanceof JSONResponse)
			return $faces;

		if (!$this->faceMapper->hasManualStateColumn())
			return $this->notMigrated('deletions of faces');

		$refused = [];
		foreach ($faces as $face) {
			if (FaceParticipation::origin($face->getManualState()) !== FaceParticipation::ORIGIN_MANUAL) {
				$refused[] = (int) $face->getId();
			}
		}
		if (!empty($refused))
			return new JSONResponse(['error' => 'only faces put there by hand can be deleted', 'faceIds' => $refused], Http::STATUS_CONFLICT);

		$this->manualFaceService->delete($this->userId, $faces);

		return new JSONResponse(['faceIds' => array_map(function (Face $face): int {
			return (int) $face->getId();
		}, $faces)], Http::STATUS_OK);
	}

	/**
	 * Ignores faces: each is put in a hidden group of its own, out of the
	 * lists of people and out of the clustering. It stays on the photo, so
	 * that it is not marked or searched again. Faces ignored already are left
	 * as they are.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @param int[] $faceIds
	 */
	public function ignoreFaces(array $faceIds = []): JSONResponse {
		$faces = $this->ownFaces($faceIds);
		if ($faces instanceof JSONResponse)
			return $faces;

		$ignored = $this->manualFaceService->ignore($this->userId, $this->settingsService->getCurrentFaceModel(), $faces);

		return new JSONResponse(['faceIds' => $ignored], Http::STATUS_OK);
	}

	/**
	 * Stops ignoring faces: they go back to the clustering, which places
	 * them again. Faces that are not ignored are left as they are.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @param int[] $faceIds
	 */
	public function unignoreFaces(array $faceIds = []): JSONResponse {
		$faces = $this->ownFaces($faceIds);
		if ($faces instanceof JSONResponse)
			return $faces;

		$unignored = $this->manualFaceService->unignore($this->userId, $faces);

		return new JSONResponse(['faceIds' => $unignored], Http::STATUS_OK);
	}

	/**
	 * The faces of a request, all of them the user's, or the answer that
	 * refuses the request: the faces come from the client, and have to be
	 * checked before anything is written.
	 *
	 * @param mixed $faceIds
	 *
	 * @return Face[]|JSONResponse
	 */
	private function ownFaces($faceIds) {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		if (!is_array($faceIds) || empty($faceIds) || count($faceIds) > self::MAX_FACES_PER_REQUEST)
			return new JSONResponse(['error' => 'between 1 and ' . self::MAX_FACES_PER_REQUEST . ' faces are needed'], Http::STATUS_BAD_REQUEST);

		$faces = [];
		foreach (array_unique($faceIds) as $faceId) {
			if (!is_numeric($faceId))
				return new JSONResponse(['error' => 'a face is not a number'], Http::STATUS_BAD_REQUEST);

			$face = $this->faceMapper->find((int) $faceId);
			if (is_null($face))
				return new JSONResponse(['error' => 'face ' . (int) $faceId . ' does not exist'], Http::STATUS_NOT_FOUND);
			if (is_null($this->imageMapper->find($this->userId, $face->getImage())))
				return new JSONResponse([], Http::STATUS_FORBIDDEN);

			$faces[] = $face;
		}

		return $faces;
	}

	/**
	 * Deletes regions the user drew to be searched. One still waiting is not
	 * searched any more; the faces the search of one already found stay, and
	 * are deleted or ignored on their own. Nothing is deleted unless every
	 * region is the user's.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @param int[] $regionIds
	 */
	public function deleteRegions(array $regionIds = []): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		if (!$this->manualRegionMapper->isAvailable())
			return $this->notMigrated('regions');

		if (empty($regionIds) || count($regionIds) > self::MAX_FACES_PER_REQUEST)
			return new JSONResponse(['error' => 'between 1 and ' . self::MAX_FACES_PER_REQUEST . ' regions are needed'], Http::STATUS_BAD_REQUEST);

		$ids = [];
		foreach (array_unique($regionIds) as $regionId) {
			if (!is_numeric($regionId))
				return new JSONResponse(['error' => 'a region is not a number'], Http::STATUS_BAD_REQUEST);

			$region = $this->manualRegionMapper->findById((int) $regionId);
			if (is_null($region))
				return new JSONResponse(['error' => 'region ' . (int) $regionId . ' does not exist'], Http::STATUS_NOT_FOUND);
			if (is_null($this->imageMapper->find($this->userId, $region->getImage())))
				return new JSONResponse([], Http::STATUS_FORBIDDEN);

			$ids[] = (int) $region->getId();
		}

		$this->manualRegionMapper->deleteRegions($ids);

		return new JSONResponse(['regionIds' => $ids], Http::STATUS_OK);
	}


	/**
	 * The clusters of the person of that name, which are the different ways
	 * their face was found.
	 *
	 * @return \OCA\FaceRecognition\Db\Cluster[]
	 */
	private function clustersOfName(string $name): array {
		$person = $this->personMapper->findByName($this->userId, $name);
		if (is_null($person)) {
			return [];
		}

		return $this->clusterMapper->findByPerson($this->userId,
			$this->settingsService->getCurrentFaceModel(), $person->getId());
	}

	/**
	 * The faces of one file, so that a client can draw the boxes that are
	 * already there before the user adds one by hand, together with what the
	 * user needs to understand them: where each face came from, whether it
	 * takes part in the clustering and why not, and how big its group is. The
	 * regions of the file that were queued for a search come along, and the
	 * minimums the clustering applies, which hold for the whole file.
	 *
	 * Whatever cannot be found out is left null and logged, instead of failing
	 * the whole answer: the faces are what the client needs above all.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function getFacesForFile(int $fileId): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$faces = $this->faceMapper->findFromFile($this->userId, $modelId, $fileId);

		$limits = [
			'minFaceSize'   => $this->settingsService->getMinimumFaceSize(),
			'minConfidence' => $this->settingsService->getMinimumConfidence(),
		];

		// The groups of all the faces at once, so that the number of queries
		// does not grow with the number of faces on the photo.
		$clusterIds = [];
		foreach ($faces as $face) {
			if (!is_null($face->getCluster())) {
				$clusterIds[] = (int) $face->getCluster();
			}
		}
		$clusterSizes = $this->unlessFailing('the size of the groups of file ' . $fileId,
			function () use ($clusterIds): array {
				return $this->faceMapper->countFacesInClusters($clusterIds);
			});
		$names = $this->unlessFailing('the names of the groups of file ' . $fileId,
			function () use ($clusterIds): array {
				return $this->clusterMapper->findPersonNames($this->userId, $clusterIds);
			});
		$ignored = $this->unlessFailing('the ignored faces of file ' . $fileId,
			function () use ($clusterIds): array {
				return $this->clusterMapper->findHiddenIds($this->userId, $clusterIds);
			});

		$withState = $this->faceMapper->hasManualStateColumn();

		$resp = [];
		foreach ($faces as $face) {
			$resp[] = $this->describeFace($face, $withState, $clusterSizes, $names, $ignored, $limits);
		}

		return new JSONResponse([
			'faces'   => $resp,
			'regions' => $this->regionsOfFile($modelId, $fileId),
			'limits'  => $limits,
		], Http::STATUS_OK);
	}

	/**
	 * One face as getFacesForFile() gives it.
	 *
	 * @param array<int, int>|null $clusterSizes [clusterId => faces], null when unknown
	 * @param array<int, string|null>|null $names [clusterId => name], null when unknown
	 * @param int[]|null $ignoredClusters the hidden clusters, where the ignored faces are; null when unknown
	 * @param array{minFaceSize: int, minConfidence: float} $limits
	 */
	private function describeFace(Face $face, bool $withState, ?array $clusterSizes, ?array $names, ?array $ignoredClusters, array $limits): array {
		$cluster = $face->getCluster();
		$ignored = !is_null($cluster) && !is_null($ignoredClusters) && in_array((int) $cluster, $ignoredClusters, true);

		$described = [
			'id'             => $face->getId(),
			'x'              => (int) $face->getX(),
			'y'              => (int) $face->getY(),
			'width'          => (int) $face->getWidth(),
			'height'         => (int) $face->getHeight(),
			'cluster'        => $cluster,
			'personName'     => is_null($cluster) || is_null($names) ? null : ($names[(int) $cluster] ?? null),
			'isManual'       => (bool) $face->getIsManual(),
			'confidence'     => (float) $face->getConfidence(),
			'manualState'    => null,
			'boxAdjusted'    => null,
			'origin'         => null,
			'clustering'     => null,
			'excludedReason' => null,
			// Null without a group, and null as well when the group is there but
			// its size could not be found out.
			'clusterSize'    => is_null($cluster) || is_null($clusterSizes) ? null : ($clusterSizes[(int) $cluster] ?? null),
			// Ignored by the user: in a hidden group of its own. Null when that
			// could not be found out.
			'ignored'        => is_null($ignoredClusters) ? null : $ignored,
		];

		// Before the migration ran there is no state to derive anything from,
		// and the face goes out as it did before.
		if (!$withState) {
			return $described;
		}

		$state = $face->getManualState();
		$participation = FaceParticipation::derive($state, $face->getIsGroupable(),
			(int) $face->getWidth(), (int) $face->getHeight(), (float) $face->getConfidence(),
			$limits['minFaceSize'], $limits['minConfidence'], $ignored);

		$described['manualState'] = $state;
		$described['boxAdjusted'] = (bool) $face->getBoxAdjusted();
		$described['origin'] = FaceParticipation::origin($state);
		$described['clustering'] = $participation['clustering'];
		$described['excludedReason'] = $participation['excludedReason'];

		return $described;
	}

	/**
	 * The regions of a file queued for a search, whatever their state. Null
	 * when they cannot be known, which tells the client to not offer the
	 * search of a region either.
	 */
	private function regionsOfFile(int $modelId, int $fileId): ?array {
		if (!$this->manualRegionMapper->isAvailable()) {
			return null;
		}

		return $this->unlessFailing('the regions of file ' . $fileId,
			function () use ($modelId, $fileId): array {
				$image = $this->imageMapper->findFromFile($this->userId, $modelId, $fileId);
				if (is_null($image)) {
					return [];
				}
				return array_map(function (ManualRegion $region): array {
					return $region->jsonSerialize();
				}, $this->manualRegionMapper->findByImage($image->getId()));
			});
	}

	/**
	 * Runs $what, and logs and gives null if it fails, for the parts of an
	 * answer that are not worth failing the whole answer for.
	 *
	 * @return mixed|null
	 */
	private function unlessFailing(string $description, callable $what) {
		try {
			return $what();
		} catch (\Throwable $e) {
			$this->logger->error(ManualFaceDetector::LOG_PREFIX . 'Could not find out ' . $description . ' of user ' . $this->userId . ': ' . $e->getMessage(), [
				'app' => 'facerecognition',
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Adds a face the user drew on a photo, and says who it is if the user
	 * gave a name.
	 *
	 * The rectangle comes as fractions (0..1) of the photo, and imageWidth and
	 * imageHeight are its natural pixel size, so that the face is stored in the
	 * same pixel coordinates as the ones the model finds.
	 *
	 * With a name, the face gets a cluster of its own, pointing at the person
	 * the user named. That is what the data model is for: a person has as many
	 * clusters as different ways their face was found, and one more of them
	 * costs nothing. Without a name it gets no cluster and no person, and the
	 * clustering places it like any other face, in the cluster of the person
	 * it looks like if there is one.
	 *
	 * Either way ManualFaceDescriptorTask searches the marked region for a
	 * face, and takes its descriptor and its confidence if it finds one. Until
	 * then the face has no confidence to speak of, and it is stored with none
	 * rather than with an invented one: the clustering leaves it alone while it
	 * waits, because it is pending, and not because of any value put here.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function addManualFace(
		int     $fileId,
		?string $personName,
		float   $x,
		float   $y,
		float   $width,
		float   $height,
		int     $imageWidth,
		int     $imageHeight
	): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$rect = $this->pixelRectOf($x, $y, $width, $height, $imageWidth, $imageHeight);
		if ($rect instanceof JSONResponse)
			return $rect;

		// The file has to exist and be one of this user's. getFileNode() looks
		// it up in their own storage, so an id of somebody else, or one that is
		// not there at all, is rejected before anything is written.
		if ($this->urlService->getFileNode($fileId) === null)
			return new JSONResponse(['error' => 'file not found or not accessible'], Http::STATUS_NOT_FOUND);

		if (!$this->faceMapper->hasManualStateColumn())
			return $this->notMigrated('manual faces');

		$modelId = $this->settingsService->getCurrentFaceModel();
		$image = $this->imageOfFile($modelId, $fileId);

		$name = trim($personName ?? '');
		$person = null;
		$clusterId = null;
		if ($name !== '') {
			$person = $this->personMapper->findOrCreateByName($this->userId, $name);
			$clusterId = $this->clusterMapper->create($this->userId, $modelId);
			$this->clusterMapper->setPerson($clusterId, $person->getId());
		}

		$face = new Face();
		$face->setImage($image->getId());
		$face->cluster = $clusterId;
		$face->setX($rect['x']);
		$face->setY($rect['y']);
		$face->setWidth($rect['width']);
		$face->setHeight($rect['height']);
		$face->setConfidence(0.0);
		$face->landmarks = [];
		$face->descriptor = [];
		$face->isGroupable = true;
		$face->isManual = true;
		$face->manualState = Face::MANUAL_STATE_PENDING;
		$face->setCreationTime(new \DateTime());

		$face = $this->faceMapper->insertManualFace($face);

		return new JSONResponse([
			'faceId'      => $face->getId(),
			'clusterId'   => $clusterId,
			'personId'    => is_null($person) ? null : $person->getId(),
			'name'        => is_null($person) ? null : $person->getName(),
			'manualState' => $face->manualState,
		], Http::STATUS_OK);
	}

	/**
	 * Queues a region of a photo to be searched for faces again, by the next
	 * run of the background job. The model runs there and never in a request.
	 *
	 * It takes the rectangle like addManualFace(), without a name: what the
	 * search finds is left for the clustering to place.
	 *
	 * @NoAdminRequired
	 * @CORS
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function addManualRegion(
		int   $fileId,
		float $x,
		float $y,
		float $width,
		float $height,
		int   $imageWidth,
		int   $imageHeight
	): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$rect = $this->pixelRectOf($x, $y, $width, $height, $imageWidth, $imageHeight);
		if ($rect instanceof JSONResponse)
			return $rect;

		if ($this->urlService->getFileNode($fileId) === null)
			return new JSONResponse(['error' => 'file not found or not accessible'], Http::STATUS_NOT_FOUND);

		if (!$this->manualRegionMapper->isAvailable() || !$this->faceMapper->hasManualStateColumn())
			return $this->notMigrated('the search of regions');

		$modelId = $this->settingsService->getCurrentFaceModel();
		$image = $this->imageOfFile($modelId, $fileId);

		$region = $this->manualRegionMapper->enqueue($image->getId(),
			$rect['x'], $rect['y'], $rect['width'], $rect['height']);

		return new JSONResponse([
			'regionId' => $region->getId(),
			'state'    => $region->getState(),
		], Http::STATUS_OK);
	}

	/**
	 * The rectangle of a request, given as fractions (0..1) of the photo, in
	 * pixels of the original image; or the response that refuses it, when it
	 * is not inside the photo or rounds down to no area at all.
	 *
	 * @return array{x: int, y: int, width: int, height: int}|JSONResponse
	 */
	private function pixelRectOf(float $x, float $y, float $width, float $height, int $imageWidth, int $imageHeight) {
		if ($imageWidth <= 0 || $imageHeight <= 0)
			return new JSONResponse(['error' => 'invalid image dimensions'], Http::STATUS_BAD_REQUEST);
		if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0 ||
		    ($x + $width) > 1.0001 || ($y + $height) > 1.0001)
			return new JSONResponse(['error' => 'invalid rectangle'], Http::STATUS_BAD_REQUEST);

		$rect = [
			'x'      => (int) round($x * $imageWidth),
			'y'      => (int) round($y * $imageHeight),
			'width'  => (int) round($width * $imageWidth),
			'height' => (int) round($height * $imageHeight),
		];
		if ($rect['width'] < 1 || $rect['height'] < 1)
			return new JSONResponse(['error' => 'rectangle too small'], Http::STATUS_BAD_REQUEST);

		return $rect;
	}

	/**
	 * The image row of a file, created if the file was never analyzed: a face
	 * and a region both need one.
	 */
	private function imageOfFile(int $modelId, int $fileId): Image {
		$image = $this->imageMapper->findFromFile($this->userId, $modelId, $fileId);
		if ($image === null) {
			$image = new Image();
			$image->setUser($this->userId);
			$image->setFile($fileId);
			$image->setModel($modelId);
			$image->setIsProcessed(false);
			$image = $this->imageMapper->insert($image);
		}
		return $image;
	}

	/**
	 * The answer while the migration that adds what a feature needs did not
	 * run yet.
	 */
	private function notMigrated(string $feature): JSONResponse {
		$this->logger->warning(ManualFaceDetector::LOG_PREFIX . ucfirst($feature) . ' are not available until the database migration of the app ran', [
			'app' => 'facerecognition',
		]);
		return new JSONResponse(['error' => $feature . ' are not available until the app is upgraded'], Http::STATUS_SERVICE_UNAVAILABLE);
	}

}

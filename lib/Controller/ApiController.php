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
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

class ApiController extends NcApiController {

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var UrlService */
	private $urlService;

	/** @var string */
	private $userId;

	public function __construct(
		$AppName,
		IRequest        $request,
		FaceMapper      $faceMapper,
		ImageMapper     $imageMapper,
		PersonMapper    $personmapper,
		SettingsService $settingsService,
		UrlService      $urlService,
		$UserId)
	{
		parent::__construct($AppName, $request);

		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
		$this->personMapper    = $personmapper;
		$this->settingsService = $settingsService;
		$this->urlService      = $urlService;
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
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$personsNames = $this->personMapper->findDistinctNames($this->userId, $modelId);
		foreach ($personsNames as $personNamed) {
			$facesCount = 0;
			$thumbFaceId = null;
			$persons = $this->personMapper->findByName($this->userId, $modelId, $personNamed->getName());
			foreach ($persons as $person) {
				$personFaces = $this->faceMapper->findFromCluster($this->userId, $person->getId(), $modelId);
				if (is_null($thumbFaceId)) {
					$thumbFaceId = $personFaces[0]->getId();
				}
				$facesCount += count($personFaces);
			}

			$respPerson = [];
			$respPerson['name'] = $personNamed->getName();
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
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->personMapper->findByName($this->userId, $modelId, $name);
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
		$personsNames = $this->personMapper->findDistinctNames($this->userId, $modelId);
		foreach ($personsNames as $personNamed) {
			$name = $personNamed->getName();
			$personFace = current($this->faceMapper->findFromPerson($this->userId, $name, $modelId, 1));

			$person = [];
			$person['name'] = $name;
			$person['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), $thumb_size);
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

		$personFace = current($this->faceMapper->findFromPerson($this->userId, $personName, $modelId, 1));
		$resp['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), $thumb_size);

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

		$clusters = $this->personMapper->findByName($this->userId, $modelId, $personName);
		if (empty($clusters))
			return new JSONResponse([], Http::STATUS_NOT_FOUND);

		if (!is_null($name)) {
			foreach ($clusters as $person) {
				$person->setName($name);
				$this->personMapper->update($person);
			}
		}
		// When change visibility it has a special treatment
		if (!is_null($visible)) {
			foreach ($clusters as $person) {
				$person->setIsVisible($visible);
				$person->setName($visible ? $name : null);
				$this->personMapper->update($person);
			}
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

		$cluster = [];
		if (!is_null($name)) {
			$cluster = $this->personMapper->find($this->userId, $clusterId);
			$cluster->setName($name);
			$cluster = $this->personMapper->update($cluster);
		}

		if (!is_null($visible)) {
			$cluster = $this->personMapper->find($this->userId, $clusterId);
			$cluster->setIsVisible($visible);
			$cluster->setName($visible ? $name : null);
			$cluster = $this->personMapper->update($cluster);
		}

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
	public function discoverPerson($minimum_count = 2, $max_previews = 40, $thumb_size = 128): JSONResponse {
		if (!$this->settingsService->getUserEnabled($this->userId))
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);

		$discoveries = [];

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->personMapper->findUnassigned($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->personMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minimum_count)
				continue;

			$personFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, $max_previews);

			$faces = [];
			foreach ($personFaces as $personFace) {
				$image = $this->imageMapper->find($this->userId, $personFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), $thumb_size);
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
		$persons = $this->personMapper->findPersonsLike($this->userId, $modelId, $query);
		foreach ($persons as $person) {
			$name = [];
			$name['name'] = $person->getName();
			$name['value'] = $person->getName();

			$personFace = current($this->faceMapper->findFromPerson($this->userId, $person->getName(), $modelId, 1));
			$name['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), $thumb_size);

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

		$face = $this->faceMapper->find($faceId);
		$person = $this->personMapper->detachFace($face->getPerson(), $faceId, $name);
		return new JSONResponse($person, Http::STATUS_OK);
	}

}

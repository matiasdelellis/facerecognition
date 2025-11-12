<?php
/**
 * @copyright Copyright (c) 2018-2024 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\ClusterMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;
use OCA\FaceRecognition\Traits\LoggerTrait;

class ClusterController extends Controller {

	use LoggerTrait;
	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var ClusterMapper */
	private $clusterMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var UrlService */
	private $urlService;

	/** @var string */
	private $userId;

	public function __construct($AppName,
	                            IRequest        $request,
	                            FaceMapper      $faceMapper,
	                            ImageMapper     $imageMapper,
	                            ClusterMapper    $personmapper,
	                            SettingsService $settingsService,
	                            UrlService      $urlService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
		$this->clusterMapper    = $personmapper;
		$this->settingsService = $settingsService;
		$this->urlService      = $urlService;
		$this->userId          = $UserId;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function find(int $id): DataResponse {
		$person = $this->clusterMapper->find($this->userId, $id);

		$resp = [];
		$faces = [];
		$personFaces = $this->faceMapper->findFromCluster($this->userId, $person->getId(), $this->settingsService->getCurrentFaceModel());
		foreach ($personFaces as $personFace) {
			$image = $this->imageMapper->find($this->userId, $personFace->getImage());

			$file =  $this->urlService->getFileNode($image->getFile());
			if ($file === null) continue;

			$face = [];
			$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
			$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);
			$faces[] = $face;
		}
		$resp['name'] = $person->getName();
		$resp['id'] = $person->getId();
		$resp['faces'] = $faces;

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function findByName(string $personName): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->clusterMapper->findByName($this->userId, $modelId, $personName);
		foreach ($persons as $person) {
			$personFaces = $this->faceMapper->findFromCluster($this->userId, $person->getId(), $modelId);

			$faces = [];
			foreach ($personFaces as $personFace) {
				$image = $this->imageMapper->find($this->userId, $personFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);
				$faces[] = $face;
			}

			$cluster = [];
			$cluster['name'] = $person->getName();
			$cluster['count'] = count($personFaces);
			$cluster['id'] = $person->getId();
			$cluster['faces'] = $faces;
			$resp['clusters'][] = $cluster;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function findUnassigned(): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);
		$this->logInfo("Finding unassigned clusters for user " . $this->userId . ", enabled: " . ($userEnabled ? "yes" : "no"));

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$minClusterSize = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->clusterMapper->findUnassigned($this->userId, $modelId);
		$this->logInfo("Found " . count($clusters) . " unassigned clusters for user " . $this->userId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minClusterSize)
				continue;

			$personFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, 40);
			$faces = [];
			foreach ($personFaces as $personFace) {
				$image = $this->imageMapper->find($this->userId, $personFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);

				$faces[] = $face;
			}

			$entry = [];
			$entry['count'] = $clusterSize;
			$entry['id'] = $cluster->getId();
			$entry['faces'] = $faces;
			$resp['clusters'][] = $entry;
			$this->logInfo("Added cluster " . $cluster->getId() . " with " . $clusterSize . " faces to response for user " . $this->userId);
		}
		$this->logInfo("Returning " . count($resp['clusters']) . " unassigned clusters for user " . $this->userId);
		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	Public function findIgnored(): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$minClusterSize = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->clusterMapper->findIgnored($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
			if ($clusterSize < $minClusterSize)
				continue;

			$personFaces = $this->faceMapper->findFromCluster($this->userId, $cluster->getId(), $modelId, 40);
			$faces = [];
			foreach ($personFaces as $personFace) {
				$image = $this->imageMapper->find($this->userId, $personFace->getImage());

				$file = $this->urlService->getFileNode($image->getFile());
				if ($file === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
				$face['fileUrl'] = $this->urlService->getRedirectToFileUrl($file);

				$faces[] = $face;
			}

			$entry = [];
			$entry['count'] = $clusterSize;
			$entry['id'] = $cluster->getId();
			$entry['faces'] = $faces;
			$resp['clusters'][] = $entry;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param bool $visible
	 *
	 * @return DataResponse
	 */
	public function setVisibility (int $id, bool $visible): DataResponse {
		$resp = array();
		$this->clusterMapper->setVisibility($id, $visible);
		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id if of cluster
	 * @param int $face id of face.
	 * @param string|null $name optional name to rename it.
	 *
	 * @return DataResponse
	 */
	public function detachFace (int $id, int $face, $name = null): DataResponse {
		$person = $this->clusterMapper->detachFace($id, $face, $name);
		return new DataResponse($person);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id of cluster
	 * @param string $name to rename them.
	 * @param int|null $face_id optional face id if you just want to name that face
	 *
	 * @return DataResponse new person with that update.
	 */
	public function updateName($id, $name, $face_id = null): DataResponse {
		if (is_null($face_id)) {
			$person = $this->clusterMapper->find($this->userId, $id);
			$person->setName($name);
			$this->clusterMapper->update($person);
		} else {
			$person = $this->clusterMapper->detachFace($id, $face_id, $name);
		}
		return new DataResponse($person);
	}

}

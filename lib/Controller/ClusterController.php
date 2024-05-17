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
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;


class ClusterController extends Controller {

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

	public function __construct($AppName,
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
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function find(int $id): DataResponse {
		$person = $this->personMapper->find($this->userId, $id);

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

		$persons = $this->personMapper->findByName($this->userId, $modelId, $personName);
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

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();
		$minClusterSize = $this->settingsService->getMinimumFacesInCluster();

		$clusters = $this->personMapper->findUnassigned($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->personMapper->countClusterFaces($cluster->getId());
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

		$clusters = $this->personMapper->findIgnored($this->userId, $modelId);
		foreach ($clusters as $cluster) {
			$clusterSize = $this->personMapper->countClusterFaces($cluster->getId());
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
		$this->personMapper->setVisibility($id, $visible);
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
		$person = $this->personMapper->detachFace($id, $face, $name);
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
			$person = $this->personMapper->find($this->userId, $id);
			$person->setName($name);
			$this->personMapper->update($person);
		} else {
			$person = $this->personMapper->detachFace($id, $face_id, $name);
		}
		return new DataResponse($person);
	}

}

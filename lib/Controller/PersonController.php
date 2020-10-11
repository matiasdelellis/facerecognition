<?php
/**
 * @copyright Copyright (c) 2018-2020 Matias De lellis <mati86dl@gmail.com>
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
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\IURLGenerator;

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


class PersonController extends Controller {

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUserSession */
	private $userSession;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var string */
	private $userId;

	public function __construct($AppName,
	                            IRequest        $request,
	                            IRootFolder     $rootFolder,
	                            IUserSession    $userSession,
	                            IURLGenerator   $urlGenerator,
	                            FaceMapper      $faceMapper,
	                            ImageMapper     $imageMapper,
	                            PersonMapper    $personmapper,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->rootFolder      = $rootFolder;
		$this->userSession     = $userSession;
		$this->urlGenerator    = $urlGenerator;
		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
		$this->personMapper    = $personmapper;
		$this->settingsService = $settingsService;
		$this->userId          = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index() {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['persons'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$personsNames = $this->personMapper->findDistinctNames($this->userId, $modelId);
		foreach ($personsNames as $personNamed) {
			$facesCount = 0;
			$faceUrl = null;
			$faces = null;
			$persons = $this->personMapper->findByName($this->userId, $modelId, $personNamed->getName());
			foreach ($persons as $person) {
				$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $modelId);
				if (is_null($faceUrl)) {
					$faceUrl = $this->getThumbUrl($personFaces[0]->getId(), 128);
				}
				$facesCount += count($personFaces);
			}

			$person = [];
			$person['name'] = $personNamed->getName();
			$person['thumbUrl'] = $faceUrl;
			$person['count'] = $facesCount;

			$resp['persons'][] = $person;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 */
	public function find(int $id) {
		$person = $this->personMapper->find($this->userId, $id);

		$resp = [];
		$faces = [];
		$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $this->settingsService->getCurrentFaceModel());
		foreach ($personFaces as $personFace) {
			$image = $this->imageMapper->find($this->userId, $personFace->getImage());
			$fileId = $image->getFile();
			if ($fileId === null) continue;

			$fileUrl = $this->getRedirectToFileUrl($fileId);
			if ($fileUrl === null) continue;

			$face = [];
			$face['thumbUrl'] = $this->getThumbUrl($personFace->getId());
			$face['fileUrl'] = $fileUrl;
			$faces[] = $face;
		}
		$resp['name'] = $person->getName();
		$resp['id'] = $person->getId();
		$resp['faces'] = $faces;

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 */
	public function findByName(string $personName) {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['name'] = $personName;
		$resp['clusters'] = 0;
		$resp['images'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->personMapper->findByName($this->userId, $modelId, $personName);
		foreach ($clusters as $cluster) {
			$resp['clusters']++;

			$faces = $this->faceMapper->findFacesFromPerson($this->userId, $cluster->getId(), $modelId);
			foreach ($faces as $face) {
				$image = $this->imageMapper->find($this->userId, $face->getImage());

				$fileId = $image->getFile();
				if ($fileId === null) continue;

				$fileUrl = $this->getRedirectToFileUrl($fileId);
				if ($fileUrl === null) continue;

				$thumbUrl = $this->getPreviewUrl($fileId, 256);
				if ($thumbUrl === null) continue;

				$image = [];
				$image['thumbUrl'] = $thumbUrl;
				$image['fileUrl'] = $fileUrl;

				$resp['images'][] = $image;
			}
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 */
	public function findClustersByName(string $personName) {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findByName($this->userId, $modelId, $personName);
		foreach ($persons as $person) {
			$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $modelId);

			$faces = [];
			foreach ($personFaces as $personFace) {
				$image = $this->imageMapper->find($this->userId, $personFace->getImage());
				$fileId = $image->getFile();
				if ($fileId === null) continue;

				$fileUrl = $this->getRedirectToFileUrl($fileId);
				if ($fileUrl === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->getThumbUrl($personFace->getId(), 50);
				$face['fileUrl'] = $fileUrl;
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
	 */
	public function findUnassignedClusters() {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findUnassigned($this->userId, $modelId);
		foreach ($persons as $person) {
			$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $modelId);
			if (count($personFaces) === 1)
				continue;

			$faces = [];
			foreach ($personFaces as $personFace) {
				$face = [];
				$face['thumbUrl'] = $this->getThumbUrl($personFace->getId(), 50);
				$faces[] = $face;
			}

			$cluster = [];
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
	 * @param string $personName
	 * @param string $name
	 */
	public function updatePerson($personName, $name) {
		$modelId = $this->settingsService->getCurrentFaceModel();
		$clusters = $this->personMapper->findByName($this->userId, $modelId, $personName);
		foreach ($clusters as $person) {
			$person->setName($name);
			$this->personMapper->update($person);
		}
		return $this->findByName($name);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param string $name
	 */
	public function updateName($id, $name) {
		$person = $this->personMapper->find ($this->userId, $id);
		$person->setName($name);
		$this->personMapper->update($person);

		$newPerson = $this->personMapper->find($this->userId, $id);
		return new DataResponse($newPerson);
	}

	/**
	 * Url to thumb face
	 *
	 * @param int $faceId face id to show
	 * @param int $size Size of face thumbnails
	 */
	private function getThumbUrl(int $faceId, int $size) {
		$params = [];
		$params['id'] = $faceId;
		$params['size'] = $size;
		return $this->urlGenerator->linkToRoute('facerecognition.face.getThumb', $params);
	}

	/**
	 * Get thumbnail of the give file id
	 *
	 * @param int $fileId file id to show
	 * @param int $sideSize side lenght to show
	 */
	public function getPreviewUrl(int $fileId, int $sideSize): ?string {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$file = current($userFolder->getById($fileId));

		if (!($file instanceof File)) {
			// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
			$this->settingsService->setNeedRemoveStaleImages(true, $this->userId);
			return null;
		}

		return '/core/preview?fileId=' . $fileId .'&x=' . $sideSize . '&y=' . $sideSize . '&a=false&v=' . $file->getETag();
	}

	/**
	 * Redirects to the file list and highlight the given file id
	 *
	 * @param int $fileId file id to show
	 */
	private function getRedirectToFileUrl(int $fileId) {
		$uid        = $this->userSession->getUser()->getUID();
		$baseFolder = $this->rootFolder->getUserFolder($uid);
		$files      = $baseFolder->getById($fileId);
		$file       = current($files);

		if(!($file instanceof File)) {
			// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
			$this->settingsService->setNeedRemoveStaleImages(true, $this->userId);
			return null;
		}

		$params = [];
		$params['dir'] = $baseFolder->getRelativePath($file->getParent()->getPath());
		$params['scrollto'] = $file->getName();

		return $this->urlGenerator->linkToRoute('files.view.index', $params);
	}

}

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

			$fileUrl = $this->urlService->getRedirectToFileUrl($fileId);
			if ($fileUrl === null) continue;

			$face = [];
			$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
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

				$fileUrl = $this->urlService->getRedirectToFileUrl($fileId);
				if ($fileUrl === null) continue;

				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
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
	public function findUnassigned() {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findUnassigned($this->userId, $modelId);
		foreach ($persons as $person) {
			$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $modelId, 40);
			if (count($personFaces) === 1)
				continue;

			$faces = [];
			foreach ($personFaces as $personFace) {
				$face = [];
				$face['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 50);
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

}

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


class PersonController extends Controller {

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
			$persons = $this->personMapper->findByName($this->userId, $modelId, $personNamed->getName());
			foreach ($persons as $person) {
				$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $modelId);
				if (is_null($faceUrl)) {
					$faceUrl = $this->urlService->getThumbUrl($personFaces[0]->getId(), 128);
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
	public function find(string $personName) {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['name'] = $personName;
		$resp['thumbUrl'] = null;
		$resp['clusters'] = 0;
		$resp['images'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$faceUrl = null;
		$modelId = $this->settingsService->getCurrentFaceModel();

		$clusters = $this->personMapper->findByName($this->userId, $modelId, $personName);
		foreach ($clusters as $cluster) {
			$resp['clusters']++;

			$faces = $this->faceMapper->findFacesFromPerson($this->userId, $cluster->getId(), $modelId);
			if (is_null($faceUrl)) {
				$faceUrl = $this->urlService->getThumbUrl($faces[0]->getId(), 128);
				$resp['thumbUrl'] = $faceUrl;
			}
			foreach ($faces as $face) {
				$image = $this->imageMapper->find($this->userId, $face->getImage());


				$fileId = $image->getFile();
				if ($fileId === null) continue;

				$fileUrl = $this->urlService->getRedirectToFileUrl($fileId);
				if ($fileUrl === null) continue;

				$thumbUrl = $this->urlService->getPreviewUrl($fileId, 256);
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
	 *
	 * @param string $personName
	 * @param string $name
	 */
	public function updateName($personName, $name) {
		$modelId = $this->settingsService->getCurrentFaceModel();
		$clusters = $this->personMapper->findByName($this->userId, $modelId, $personName);
		foreach ($clusters as $person) {
			$person->setName($name);
			$this->personMapper->update($person);
		}
		return $this->find($name);
	}

	/**
	 * @NoAdminRequired
	 */
	public function autocomplete(string $query) {
		$resp = array();

		if (!$this->settingsService->getUserEnabled($this->userId))
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findPersonsLike($this->userId, $modelId, $query);
		foreach ($persons as $person) {
			$name = [];
			$name['name'] = $person->getName();
			$name['value'] = $person->getName();
			$resp[] = $name;
		}
		return new DataResponse($resp);
    }

}

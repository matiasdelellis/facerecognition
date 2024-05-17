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
	 *
	 * @return DataResponse
	 */
	public function index(): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['persons'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$personsNames = $this->personMapper->findDistinctNames($this->userId, $modelId);
		foreach ($personsNames as $personNamed) {
			$name = $personNamed->getName();
			$personFace = current($this->faceMapper->findFromPerson($this->userId, $name, $modelId, 1));

			$person = [];
			$person['name'] = $name;
			$person['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 128);
			$person['count'] = $this->imageMapper->countFromPerson($this->userId, $modelId, $name);

			$resp['persons'][] = $person;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function find(string $personName): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();
		$resp['name'] = $personName;
		$resp['thumbUrl'] = null;
		$resp['images'] = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$personFace = current($this->faceMapper->findFromPerson($this->userId, $personName, $modelId, 1));
		$resp['thumbUrl'] = $this->urlService->getThumbUrl($personFace->getId(), 128);

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

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $personName
	 * @param string $name
	 *
	 * @return DataResponse
	 */
	public function updateName($personName, $name): DataResponse {
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
	 *
	 * @param string $personName
	 * @param bool $visible
	 *
	 * @return DataResponse
	*/
	public function setVisibility ($personName, bool $visible): DataResponse {
		$modelId = $this->settingsService->getCurrentFaceModel();
		$clusters = $this->personMapper->findByName($this->userId, $modelId, $personName);
		foreach ($clusters as $cluster) {
			$this->personMapper->setVisibility($cluster->getId(), $visible);
		}
		return $this->find($personName);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function autocomplete(string $query): DataResponse {
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

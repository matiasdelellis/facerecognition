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

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class PersonApiController extends OCSController {

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
	 * Get all persons with faces and file asociated
	 *
	 * @NoAdminRequired
	 */
	public function getPersons() {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new DataResponse($resp);

		$modelId = $this->settingsService->getCurrentFaceModel();

		$persons = $this->personMapper->findAllNamed($this->userId, $modelId);
		foreach ($persons as $person) {
			$respPerson = [];
			$respPerson['name'] = $person->getName();
			$respPerson['id'] = $person->getId();
			$respPerson['faces'] = array();

			$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $modelId);
			foreach ($personFaces as $personFace) {
				$respFace = [];
				$respFace['id'] = $personFace->id;

				$image = $this->imageMapper->find($this->userId, $personFace->image);
				$respFace['file-id'] = $image->file;

				$respPerson['faces'][] = $respFace;
			}

			$resp[] = $respPerson;
		}

		return new DataResponse($resp);
	}

}

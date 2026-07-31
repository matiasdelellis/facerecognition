<?php
/**
 * @copyright Copyright (c) 2021 Ming Tsang <nkming2@gmail.com>
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
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\OCSController;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

class OcsApiController extends OCSController {

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
		$UserId)
	{
		parent::__construct($AppName, $request);

		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
$this->clusterMapper  = $clusterMapper;
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
	 * @return DataResponse
	 */
	public function getPersonsV1(): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new DataResponse($resp);

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

		return new DataResponse($resp);
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
	 * @return DataResponse
	 */
	public function getFacesByPerson(string $name): DataResponse {
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);

		$resp = array();

		if (!$userEnabled)
			return new DataResponse($resp);

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

		return new DataResponse($resp);
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

}

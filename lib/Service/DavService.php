<?php
/**
 * @copyright Copyright (c) 2021 Matias De lellis <mati86dl@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Service;

use OCA\FaceRecognition\AppInfo\Application;

use OCA\DAV\Connector\Sabre\Node as SabreNode;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\FileService;

use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Dav\PersonsList;

class DavService {

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var FileService */
	private $fileService;

	/** @var SettingsService */
	private $settingsService;

	private $userId;

	/**
	 * @var string
	 */
	private $appName;

	/**
	 * DavService constructor.
	 * @param string $appName
	 * @param string|null $userId
	 */
	public function __construct(string          $appName,
	                            ImageMapper     $imageMapper,
	                            PersonMapper    $personMapper,
	                            FaceMapper      $faceMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService,
	                            ?string         $userId)
	{
		$this->appName         = $appName;
		$this->imageMapper     = $imageMapper;
		$this->personMapper    = $personMapper;
		$this->faceMapper      = $faceMapper;
		$this->fileService     = $fileService;
		$this->settingsService = $settingsService;
		$this->userId          = $userId;
	}

	/**
	 * Get approval state of a given file for a given user
	 * @param int $fileId
	 * @param string|null $userId
	 * @return array state and rule id
	 */
	public function getFaceRecognitionImage(int $fileId, ?string $userId): array {
		$response = array();
		if (is_null($userId)) {
			$response['state'] = Application::STATE_UNDEFINED;
			return $response;
		}

		if (!$this->settingsService->getUserEnabled($userId)) {
			$response['state'] = Application::STATE_DISABLED;
			return $response;
		}

		$modelId = $this->settingsService->getCurrentFaceModel();
		$image = $this->imageMapper->findFromFile($userId, $modelId, $fileId);
		if (!$image->getIsProcessed()) {
			$response['state'] = Application::STATE_UNDEFINED;
			return $response;
		}

		$personsImage = array();
		$faces = $this->faceMapper->findFromFile($userId, $modelId, $fileId);
		foreach ($faces as $face) {
			$person = $this->personMapper->find($this->userId, $face->getPerson());
			if (!$person->getIsVisible()) {
				continue;
			}

			$facePerson = array();
			$facePerson['id'] = $person->getId();
			$facePerson['name'] = $person->getName();
			$facePerson['top'] = $face->getTop();
			$facePerson['left'] = $face->getLeft();
			$facePerson['width'] = $face->getRight() - $face->getLeft();
			$facePerson['height'] = $face->getBottom() - $face->getTop();

			$personsImage[] = $facePerson;
		}

		if (count($personsImage)) {
			$response['persons'] = $personsImage;
			return $response;
		}

		$response['state'] = Application::STATE_NO_PERSONS;
		return $response;
	}

	/**
	 * Get persons as a WebDav attribute
	 *
	 * @param PropFind $propFind
	 * @param INode $node
	 * @return void
	 */
	public function propFind(PropFind $propFind, INode $node): void {
		if (!$node instanceof SabreNode) {
			return;
		}

		$nodeId = $node->getId();
		$image = $this->getFaceRecognitionImage($nodeId, $this->userId);
		$propFind->handle(
			Application::DAV_PROPERTY_PERSONS, function() use ($nodeId, $image) {
				if (isset($image['persons'])) {
					return new PersonsList($image['persons']);
				}
				return $image['state'];
			}
		);
	}

}

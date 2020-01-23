<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2017, 2018, 2020 Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Search;

use OCA\FaceRecognition\AppInfo\Application;

use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Service\SettingsService;

/**
 * Provide search results from the 'facerecognition' app
 */
class Provider extends \OCP\Search\Provider {

	/** @var ImageMapper Image mapper */
	private $imageMapper;

	/** @var SettingsService Settings service */
	private $settingsService;

	public function __construct() {
		$app = new Application();
		$container = $app->getContainer();

		$this->imageMapper     = $container->query(\OCA\FaceRecognition\Db\ImageMapper::class);
		$this->settingsService = $container->query(\OCA\FaceRecognition\Service\SettingsService::class);
	}

	/**
	 *
	 * @param string $query
	 * @return \OCP\Search\Result
	*/
	function search($query) {

		$userId = \OC::$server->getUserSession()->getUser()->getUID();
		$ownerView = new \OC\Files\View('/'. $userId . '/files');

		$model = $this->settingsService->getCurrentFaceModel();

		$searchresults = array();

		$results = $this->imageMapper->findImagesFromPerson ($userId, $query, $model);
		foreach($results as $result) {
			$fileId = $result->getFile();
			try {
				$path = $ownerView->getPath($fileId);
			} catch (\OCP\Files\NotFoundException $e) {
				continue;
			}
			$fileInfo = $ownerView->getFileInfo($path);
			//$searchresults[] = new \OCA\FaceRecognition\Search\Result($returnData);
			$searchresults[] = new \OC\Search\Result\Image($fileInfo);
		}

		return $searchresults;

	}

}

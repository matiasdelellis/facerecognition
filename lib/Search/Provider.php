<?php
/**
 * Face Recognition
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE.md file.
 *
 * @copyright 2017 Matias De lellis <mati86dl@delellis.com>
 */

namespace OCA\FaceRecognition\Search;
use OCA\FaceRecognition\AppInfo\Application;

/**
 * Provide search results from the 'facerecognition' app
 */
class Provider extends \OCP\Search\Provider {

	private $faceMapper;
	//private $l10N;

	public function __construct() {
		$app = new Application('facerecognition');
		$container = $app->getContainer();

		$this->app = $app;
		$this->faceMapper = $container->query(\OCA\FaceRecognition\Db\FaceMapper::class);
		//$this->l10n = $container->query('L10N');
	}

	/**
	 *
	 * @param string $query
	 * @return \OCP\Search\Result
	*/
	function search($query) {

		$userId = \OCP\User::getUser();
		$ownerView = new \OC\Files\View('/'. $userId . '/files');

		$results = $this->faceMapper->findFaces($userId, $query);
		$searchresults = array();
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

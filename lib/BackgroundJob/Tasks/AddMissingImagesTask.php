<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCP\IConfig;
use OCP\IUser;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

/**
 * Task that, for each user, crawls for all images in filesystem and insert them in database.
 * This is job that normally does file watcher, but this should be done at least once,
 * after app is installed (or re-enabled).
 */
class AddMissingImagesTask extends FaceRecognitionBackgroundTask {
	const FULL_IMAGE_SCAN_DONE_KEY = "full_image_scan_done";

	/** @var IConfig Config */
	private $config;

	/** @var ImageMapper Image mapper */
	private $imageMapper;

	/**
	 * @param IConfig $config Config
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(IConfig $config, ImageMapper $imageMapper) {
		parent::__construct();
		$this->config = $config;
		$this->imageMapper = $imageMapper;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Crawl for missing images for each user and insert them in DB";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// Check if we are called for one user only, or for all user in instance.
		$insertedImages = 0;
		$eligable_users = array();
		if (is_null($this->context->user)) {
			$this->context->userManager->callForSeenUsers(function (IUser $user) use (&$eligable_users) {
				$eligable_users[] = $user->getUID();
			});
		} else {
			$eligable_users[] = $this->context->user->getUID();
		}

		foreach($eligable_users as $user) {
			$userEnabled = $this->config->getUserValue($user, 'facerecognition', 'enabled', 'false');
			if ($userEnabled === 'false') {
				// Completely skip this task for this user, seems that disable analysis
				$this->logDebug('Skipping image scan for user ' . $user . ' that has disabled the analysis');
				continue;
			}

			$fullImageScanDone = $this->config->getUserValue($user, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
			if ($fullImageScanDone === 'true') {
				// Completely skip this task for this user, seems that we already did full scan for him
				$this->logDebug('Skipping full image scan for user ' . $user);
				continue;
			}

			$insertedImages += $this->addMissingImagesForUser($user, $model);
			$this->config->setUserValue($user, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'true');
			yield;
		}

		$this->context->propertyBag['AddMissingImagesTask_insertedImages'] = $insertedImages;
		return true;
	}

	/**
	 * Crawl filesystem for a given user
	 *
	 * @param string $userId ID of the user for which to crawl images for
	 * @param int $model Used model
	 * @return int Number of missing images found
	 */
	private function addMissingImagesForUser(string $userId, int $model): int {
		$this->logInfo(sprintf('Finding missing images for user %s', $userId));
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($userId);

		$userFolder = $this->context->rootFolder->getUserFolder($userId);
		return $this->parseUserFolder($model, $userFolder);
	}

	/**
	 * Recursively crawls given folder for a given user
	 *
	 * @param int $model Used model
	 * @param Folder $folder Folder to recursively search images in
	 * @return int Number of missing images found
	 */
	private function parseUserFolder(int $model, Folder $folder): int {
		$insertedImages = 0;
		$nodes = $this->getPicturesFromFolder($folder);
		foreach ($nodes as $file) {
			$this->logDebug('Found ' . $file->getPath());

			$image = new Image();
			$image->setUser($file->getOwner()->getUid());
			$image->setFile($file->getId());
			$image->setModel($model);
			// todo: this check/insert logic for each image is so inefficient it hurts my mind
			if ($this->imageMapper->imageExists($image) === null) {
				// todo: can we have larger transaction with bulk insert?
				$this->imageMapper->insert($image);
				$insertedImages++;
			}
		}

		return $insertedImages;
	}

	/**
	 * Return all images from a given folder.
	 *
	 * TODO: It is inefficient since it copies the array recursively.
	 *
	 * @param Folder $folder Folder to get images from
	 * @return array List of all images and folders to continue recursive crawling
	 */
	private function getPicturesFromFolder(Folder $folder, $results = array()) {
		// todo: should we also care about this too: instanceOfStorage(ISharedStorage::class);
		if ($folder->getStorage()->instanceOfStorage(IHomeStorage::class) === false) {
			return $results;
		}

		$nodes = $folder->getDirectoryListing();

		foreach ($nodes as $node) {
			if ($node instanceof Folder and !$node->nodeExists('.nomedia')) {
				$results = $this->getPicturesFromFolder($node, $results);
			} else if ($node instanceof File) {
				if (Requirements::isImageTypeSupported($node->getMimeType())) {
					$results[] = $node;
				}
			}
		}

		return $results;
	}
}

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
use OCP\Files\Node;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;
use OCA\FaceRecognition\Service\FileService;

/**
 * Task that, for each user, crawls for all images in database,
 * checks if they actually exist and removes them if they don't.
 * It should be executed rarely.
 */
class StaleImagesRemovalTask extends FaceRecognitionBackgroundTask {
	const STALE_IMAGES_REMOVAL_NEEDED_KEY = "stale_images_removal_needed";
	const STALE_IMAGES_LAST_CHECKED_KEY = "stale_images_last_checked";

	/** @var IConfig Config */
	private $config;

	/** @var ImageMapper Image mapper */
	private $imageMapper;

	/** @var FaceMapper Face mapper */
	private $faceMapper;

	/** @var PersonMapper Person mapper */
	private $personMapper;

	/** @var FileService */
	private $fileService;

	/**
	 * @param IConfig $config Config
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FaceMapper $faceMapper Face mapper
	 * @param PersonMapper $personMapper Person mapper
	 * @param FileService $fileService File Service
	 */
	public function __construct(IConfig      $config,
	                            ImageMapper  $imageMapper,
	                            FaceMapper   $faceMapper,
	                            PersonMapper $personMapper,
	                            FileService  $fileService) {
		parent::__construct();
		$this->config = $config;
		$this->imageMapper  = $imageMapper;
		$this->faceMapper   = $faceMapper;
		$this->personMapper = $personMapper;
		$this->fileService  = $fileService;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Crawl for stale images (either missing in filesystem or under .nomedia) and remove them from DB";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// Check if we are called for one user only, or for all user in instance.
		$staleRemovedImages = 0;
		$eligable_users = array();
		if (is_null($this->context->user)) {
			$this->context->userManager->callForSeenUsers(function (IUser $user) use (&$eligable_users) {
				$eligable_users[] = $user->getUID();
			});
		} else {
			$eligable_users[] = $this->context->user->getUID();
		}

		foreach($eligable_users as $user) {
			$staleImagesRemovalNeeded = $this->config->getUserValue(
				$user, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'false');
			if ($staleImagesRemovalNeeded === 'false') {
				// Completely skip this task for this user, seems that we already did full scan for him
				$this->logDebug(sprintf('Skipping stale images removal for user %s as there is no need for it', $user));
				continue;
			}

			// Since method below can take long time, it is generator itself
			$generator = $this->staleImagesRemovalForUser($user, $model);
			foreach ($generator as $_) {
				yield;
			}
			$staleRemovedImages += $generator->getReturn();

			$this->config->setUserValue($user, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'false');
			yield;
		}

		$this->context->propertyBag['StaleImagesRemovalTask_staleRemovedImages'] = $staleRemovedImages;
		return true;
	}

	/**
	 * Gets all images in database for a given user. For each image, check if it
	 * actually present in filesystem (and there is no .nomedia for it) and removes
	 * it from database if it is not present.
	 *
	 * @param string $userId ID of the user for which to remove stale images for
	 * @param int $model Used model
	 * @return \Generator|int Returns generator during yielding and finally returns int,
	 * which represent number of stale images removed
	 */
	private function staleImagesRemovalForUser(string $userId, int $model) {

		$this->fileService->setupFS($userId);

		$this->logDebug(sprintf('Getting all images for user %s', $userId));
		$allImages = $this->imageMapper->findImages($userId, $model);
		$this->logDebug(sprintf('Found %d images for user %s', count($allImages), $userId));
		yield;

		// Find if we stopped somewhere abruptly before. If we are, we need to start from that point.
		// If there is value, we start from beggining. Important is that:
		// * There needs to be some (any!) ordering here, we used "id" for ordering key
		// * New images will be processed, or some might be checked more than once, and that is OK
		//   Important part is that we make continuous progess.
		$lastChecked = intval($this->config->getUserValue(
			$userId, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_LAST_CHECKED_KEY, '0'));
		$this->logDebug(sprintf('Last checked image id for user %s is %d', $userId, $lastChecked));
		yield;

		// Now filter by those above last checked and sort remaining images
		$allImages = array_filter($allImages, function ($i) use($lastChecked) {
			return $i->id > $lastChecked;
		});
		usort($allImages, function ($i1, $i2) {
			return $i1->id <=> $i2->id;
		});
		$this->logDebug(sprintf(
			'After filtering and sorting, there is %d remaining stale images to check for user %s',
			count($allImages), $userId));
		yield;

		// Now iterate and check remaining images
		$processed = 0;
		$imagesRemoved = 0;
		foreach ($allImages as $image) {
			// Try to get the file to ensure that exist.
			try {
				$file = $this->fileService->getFileById($image->getFile(), $userId);
			} catch (\OCP\Files\NotFoundException $e) {
				$file = null;
			}

			// Delete image doesn't exist anymore in filesystem or it is under .nomedia
			if (($file === null) || (!$this->fileService->isAllowedNode($file)) ||
			    ($this->fileService->isUnderNoDetection($file))) {
				$this->deleteImage($image, $userId);
				$imagesRemoved++;
			}

			// Remember last processed image
			$this->config->setUserValue(
				$userId, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_LAST_CHECKED_KEY, $image->id);

			// Yield from time to time
			$processed++;
			if ($processed % 10 === 0) {
				$this->logDebug(sprintf('Processed %d/%d stale images for user %s', $processed, count($allImages), $userId));
				yield;
			}
		}

		// Remove this value when we are done, so next cleanup can start from 0
		$this->config->deleteUserValue($userId, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_LAST_CHECKED_KEY);
		return $imagesRemoved;
	}

	private function deleteImage(Image $image, string $userId) {
		$this->logInfo(sprintf('Removing stale image %d for user %s', $image->id, $userId));
		// note that invalidatePersons depends on existence of faces for a given image,
		// and we must invalidate before we delete faces!
		// TODO: this is same method as in Watcher, find where to unify them.
		$this->personMapper->invalidatePersons($image->id);
		$this->faceMapper->removeFaces($image->id);
		$this->imageMapper->delete($image);
	}
}

<?php
/**
 * @copyright Copyright (c) 2017-2020 Matias De lellis <mati86dl@gmail.com>
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

use OCP\IUser;

use OCP\Files\File;
use OCP\Files\Folder;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Task that, for each user, crawls for all images in filesystem and insert them in database.
 * This is job that normally does file watcher, but this should be done at least once,
 * after app is installed (or re-enabled).
 */
class AddMissingImagesTask extends FaceRecognitionBackgroundTask {
	const FULL_IMAGE_SCAN_DONE_KEY = "full_image_scan_done";

	/** @var ImageMapper Image mapper */
	private $imageMapper;

	/** @var FileService */
	private $fileService;

	/** @var SettingsService Settings service */
	private $settingsService;

	/**
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FileService $fileService File Service
	 * @param SettingsService $settingsService Settings Service
	 */
	public function __construct(ImageMapper     $imageMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->imageMapper     = $imageMapper;
		$this->fileService     = $fileService;
		$this->settingsService = $settingsService;
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

		// Check if we are called for one user only, or for all user in instance.
		$insertedImages = 0;
		$eligable_users = $this->context->getEligibleUsers();
		foreach($eligable_users as $user) {
			if (!$this->settingsService->getUserEnabled($user)) {
				// Completely skip this task for this user, seems that disable analysis
				$this->logInfo('Skipping image scan for user ' . $user . ' that has disabled the analysis');
				continue;
			}

			if (!$this->context->isRunningInSyncMode() &&
			    $this->settingsService->getUserFullScanDone($user)) {
				// Completely skip this task for this user, seems that we already did full scan for him
				$this->logDebug('Skipping full image scan for user ' . $user);
				continue;
			}

			$insertedImages += $this->addMissingImagesForUser($user, $this->settingsService->getCurrentFaceModel());
			$this->settingsService->setUserFullScanDone(true, $user);
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
		$this->fileService->setupFS($userId);

		$userFolder = $this->fileService->getUserFolder($userId);
		return $this->parseUserFolder($userId, $model, $userFolder);
	}

	/**
	 * Recursively crawls given folder for a given user
	 *
	 * @param int $model Used model
	 * @param Folder $folder Folder to recursively search images in
	 * @return int Number of missing images found
	 */
	private function parseUserFolder(string $userId, int $model, Folder $folder): int {
		$insertedImages = 0;
		$nodes = $this->fileService->getPicturesFromFolder($folder);
		foreach ($nodes as $file) {
			$this->logDebug('Found ' . $file->getPath());

			$image = new Image();
			$image->setUser($userId);
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

}

<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\FaceRecognition;

use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;

use OCA\FaceRecognition\FaceManagementService;
use OCA\FaceRecognition\Service\FileService;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\StaleImagesRemovalTask;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

class Watcher {

	/** @var IConfig Config */
	private $config;

	/** @var ILogger Logger */
	private $logger;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var FileService */
	private $fileService;

	/** @var FaceManagementService */
	private $faceManagementService;

	/**
	 * Watcher constructor.
	 *
	 * @param IConfig $config
	 * @param ILogger $logger
	 * @param IUserManager $userManager
	 * @param FaceMapper $faceMapper
	 * @param ImageMapper $imageMapper
	 * @param PersonMapper $personMapper
	 * @param FileService $fileService
	 * @param FaceManagementService $faceManagementService
	 */
	public function __construct(IConfig               $config,
	                            ILogger               $logger,
	                            IUserManager          $userManager,
	                            FaceMapper            $faceMapper,
	                            ImageMapper           $imageMapper,
	                            PersonMapper          $personMapper,
	                            FileService           $fileService,
	                            FaceManagementService $faceManagementService)
	{
		$this->config                = $config;
		$this->logger                = $logger;
		$this->userManager           = $userManager;
		$this->faceMapper            = $faceMapper;
		$this->imageMapper           = $imageMapper;
		$this->personMapper          = $personMapper;
		$this->fileService           = $fileService;
		$this->faceManagementService = $faceManagementService;
	}

	/**
	 * A node has been updated. We just store the file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function postWrite(Node $node) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));
		$handleSharedFiles = $this->config->getAppValue('facerecognition', 'handle-shared-files', 'false');

		if ($this->fileService->isUserFile($node)) {
			$owner = $node->getOwner()->getUid();
		}
		else if ($handleSharedFiles === 'true' && $this->fileService->isSharedFile($node)) {
			// If we are going to analyze the shared files, we must 'appropriate' it.
			$owner = \OC::$server->getUserSession()->getUser()->getUID();
		}
		else {
			// Nextcloud also sends the Hooks when create thumbnails for example.
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		$enabled = $this->config->getUserValue($owner, 'facerecognition', 'enabled', 'false');
		if ($enabled !== 'true') {
			$this->logger->debug('The user ' . $owner . ' not have the analysis enabled. Skipping');
			return;
		}

		if ($node->getName() === FileService::NOMEDIA_FILE) {
			// If user added this file, it means all images in this and all child directories should be removed.
			// Instead of doing that here, it's better to just add flag that image removal should be done.
			$this->config->setUserValue($owner, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'true');
			return;
		}

		if ($node->getName() === FileService::FACERECOGNITION_SETTINGS_FILE) {
			// This file can enable or disable the analysis, so I have to look for new files and forget others.
			$this->config->setUserValue($owner, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'true');
			$this->config->setUserValue($owner, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
			return;
		}

		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		if (!$this->userManager->userExists($owner)) {
			$this->logger->debug(
				"Skipping inserting image " . $node->getName() . " because it seems that user  " . $owner . " doesn't exist");
			return;
		}

		if ($this->fileService->isUnderNoDetection($node)) {
			$this->logger->debug(
				"Skipping inserting image " . $node->getName() . " because is inside an folder that contains a .nomedia file");
			return;
		}

		$this->logger->debug("Inserting/updating image " . $node->getName() . " for face recognition");

		$image = new Image();
		$image->setUser($owner);
		$image->setFile($node->getId());
		$image->setModel($model);

		$imageId = $this->imageMapper->imageExists($image);
		if ($imageId === null) {
			// todo: can we have larger transaction with bulk insert?
			$this->imageMapper->insert($image);
		} else {
			$this->imageMapper->resetImage($image);
			// note that invalidatePersons depends on existence of faces for a given image,
			// and we must invalidate before we delete faces!
			$this->personMapper->invalidatePersons($imageId);

			// Fetch all faces to be deleted before deleting them, and then delete them
			$facesToRemove = $this->faceMapper->findByImage($imageId);
			$this->faceMapper->removeFaces($imageId);

			// If any person is now without faces, remove those (empty) persons
			foreach ($facesToRemove as $faceToRemove) {
				if ($faceToRemove->getPerson() !== null) {
					$this->personMapper->removeIfEmpty($faceToRemove->getPerson());
				}
			}
		}
	}

	/**
	 * A node has been deleted. Remove faces with file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function postDelete(Node $node) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));
		$handleSharedFiles = $this->config->getAppValue('facerecognition', 'handle-shared-files', 'false');

		if ($this->fileService->isUserFile($node)) {
			$owner = $node->getOwner()->getUid();
		}
		else if ($handleSharedFiles === 'true' && $this->fileService->isSharedFile($node)) {
			// If we are going to analyze the shared files, we must 'appropriate' it.
			$owner = \OC::$server->getUserSession()->getUser()->getUID();
		}
		else {
			// Nextcloud also sends the Hooks when create thumbnails for example.
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		$enabled = $this->config->getUserValue($owner, 'facerecognition', 'enabled', 'false');
		if ($enabled !== 'true') {
			$this->logger->debug('The user ' . $owner . ' not have the analysis enabled. Skipping');
			return;
		}

		if ($node->getName() === FileService::NOMEDIA_FILE) {
			// If user deleted file named .nomedia, that means all images in this and all child directories should be added.
			// But, instead of doing that here, better option seem to be to just reset flag that image scan is not done.
			// This will trigger another round of image crawling in AddMissingImagesTask for this user and those images will be added.
			$this->config->setUserValue($owner, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
			return;
		}

		if ($node->getName() === FileService::FACERECOGNITION_SETTINGS_FILE) {
			// This file can enable or disable the analysis, so I have to look for new files and forget others.
			$this->config->setUserValue($owner, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'true');
			$this->config->setUserValue($owner, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
			return;
		}

		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		$this->logger->debug("Deleting image " . $node->getName() . " from face recognition");

		$image = new Image();
		$image->setUser($owner);
		$image->setFile($node->getId());
		$image->setModel($model);

		$imageId = $this->imageMapper->imageExists($image);
		if ($imageId !== null) {
			// note that invalidatePersons depends on existence of faces for a given image,
			// and we must invalidate before we delete faces!
			$this->personMapper->invalidatePersons($imageId);

			// Fetch all faces to be deleted before deleting them, and then delete them
			$facesToRemove = $this->faceMapper->findByImage($imageId);
			$this->faceMapper->removeFaces($imageId);

			$image->setId($imageId);
			$this->imageMapper->delete($image);

			// If any person is now without faces, remove those (empty) persons
			foreach ($facesToRemove as $faceToRemove) {
				if ($faceToRemove->getPerson() !== null) {
					$this->personMapper->removeIfEmpty($faceToRemove->getPerson());
				}
			}
		}
	}

	/**
	 * A user has been deleted. Cleanup everything from this user.
	 *
	 * @param \OC\User\User $user Deleted user
	 */
	public function postUserDelete(\OC\User\User $user) {
		$userId = $user->getUid();
		$this->faceManagementService->resetAllForUser($userId);
		$this->logger->info("Removed all face recognition data for deleted user " . $userId);
	}
}

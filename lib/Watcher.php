<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017-2020 Matias De lellis <mati86dl@gmail.com>
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
use OCP\Files\Node;
use OCP\ILogger;
use OCP\IUserManager;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FaceManagementService;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;

use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\Requirements;

class Watcher {

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

	/** @var ModelManager */
	private $modelManager;

	/** @var SettingsService */
	private $settingsService;

	/** @var FileService */
	private $fileService;

	/** @var FaceManagementService */
	private $faceManagementService;

	/**
	 * Watcher constructor.
	 *
	 * @param ILogger $logger
	 * @param IUserManager $userManager
	 * @param FaceMapper $faceMapper
	 * @param ImageMapper $imageMapper
	 * @param PersonMapper $personMapper
	 * @param ModelManager $modelManager
	 * @param SettingsService $settingsService
	 * @param FileService $fileService
	 * @param FaceManagementService $faceManagementService
	 */
	public function __construct(ILogger               $logger,
	                            IUserManager          $userManager,
	                            FaceMapper            $faceMapper,
	                            ImageMapper           $imageMapper,
	                            PersonMapper          $personMapper,
	                            ModelManager          $modelManager,
	                            SettingsService       $settingsService,
	                            FileService           $fileService,
	                            FaceManagementService $faceManagementService)
	{
		$this->logger                = $logger;
		$this->userManager           = $userManager;
		$this->faceMapper            = $faceMapper;
		$this->imageMapper           = $imageMapper;
		$this->personMapper          = $personMapper;
		$this->modelManager          = $modelManager;
		$this->settingsService       = $settingsService;
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
		if (!$this->fileService->isAllowedNode($node)) {
			// Nextcloud sends the Hooks when create thumbnails for example.
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		$model = $this->modelManager->getCurrentModel();
		if (is_null($model)) {
			$this->logger->debug("Skipping inserting file since there are no configured model");
			return;
		}

		$owner = \OC::$server->getUserSession()->getUser()->getUID();
		if (!$this->userManager->userExists($owner)) {
			$this->logger->debug(
				"Skipping inserting file " . $node->getName() . " because it seems that user  " . $owner . " doesn't exist");
			return;
		}

		$enabled = $this->settingsService->getUserEnabled($owner);
		if (!$enabled) {
			$this->logger->debug('The user ' . $owner . ' not have the analysis enabled. Skipping');
			return;
		}

		if ($node->getName() === FileService::NOMEDIA_FILE) {
			// If user added this file, it means all images in this and all child directories should be removed.
			// Instead of doing that here, it's better to just add flag that image removal should be done.
			$this->settingsService->setNeedRemoveStaleImages(true, $owner);
			return;
		}

		if ($node->getName() === FileService::FACERECOGNITION_SETTINGS_FILE) {
			// This file can enable or disable the analysis, so I have to look for new files and forget others.
			$this->settingsService->setNeedRemoveStaleImages(true, $owner);
			$this->settingsService->setUserFullScanDone(false, $owner);
			return;
		}

		if (!$model->isMimeTypeSupported($node->getMimeType())) {
			// The file is not an image or the model does not support it
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
		$image->setModel($model->getId());

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
			$this->faceMapper->removeFromImage($imageId);

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
		if (!$this->fileService->isAllowedNode($node)) {
			// Nextcloud sends the Hooks when create thumbnails for example.
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		$model = $this->modelManager->getCurrentModel();
		if (is_null($model)) {
			$this->logger->debug("Skipping deleting file since there are no configured model");
			return;
		}

		$owner = \OC::$server->getUserSession()->getUser()->getUID();
		$enabled = $this->settingsService->getUserEnabled($owner);
		if (!$enabled) {
			$this->logger->debug('The user ' . $owner . ' not have the analysis enabled. Skipping');
			return;
		}

		if ($node->getName() === FileService::NOMEDIA_FILE) {
			// If user deleted file named .nomedia, that means all images in this and all child directories should be added.
			// But, instead of doing that here, better option seem to be to just reset flag that image scan is not done.
			// This will trigger another round of image crawling in AddMissingImagesTask for this user and those images will be added.
			$this->settingsService->setNeedRemoveStaleImages(true, $owner);
			return;
		}

		if ($node->getName() === FileService::FACERECOGNITION_SETTINGS_FILE) {
			// This file can enable or disable the analysis, so I have to look for new files and forget others.
			$this->settingsService->setNeedRemoveStaleImages(true, $owner);
			$this->settingsService->setUserFullScanDone(false, $owner);
			return;
		}

		if (!$model->isMimeTypeSupported($node->getMimeType())) {
			// The file is not an image or the model does not support it
			return;
		}

		$this->logger->debug("Deleting image " . $node->getName() . " from face recognition");

		$image = new Image();
		$image->setUser($owner);
		$image->setFile($node->getId());
		$image->setModel($model->getId());

		$imageId = $this->imageMapper->imageExists($image);
		if ($imageId !== null) {
			// note that invalidatePersons depends on existence of faces for a given image,
			// and we must invalidate before we delete faces!
			$this->personMapper->invalidatePersons($imageId);

			// Fetch all faces to be deleted before deleting them, and then delete them
			$facesToRemove = $this->faceMapper->findByImage($imageId);
			$this->faceMapper->removeFromImage($imageId);

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

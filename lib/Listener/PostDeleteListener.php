<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017-2021 Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2021 Ming Tsang <nkming2@gmail.com>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

use OCP\Files\Folder;
use OCP\Files\Events\Node\NodeDeletedEvent;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use Psr\Log\LoggerInterface;

class PostDeleteListener implements IEventListener {

	/** @var LoggerInterface $logger */
	private $logger;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var FileService */
	private $fileService;

	public function __construct(LoggerInterface       $logger,
	                            FaceMapper            $faceMapper,
	                            ImageMapper           $imageMapper,
	                            PersonMapper          $personMapper,
	                            SettingsService       $settingsService,
	                            FileService           $fileService)
	{
		$this->logger                = $logger;
		$this->faceMapper            = $faceMapper;
		$this->imageMapper           = $imageMapper;
		$this->personMapper          = $personMapper;
		$this->settingsService       = $settingsService;
		$this->fileService           = $fileService;
	}


	/**
	 * A node has been deleted. Remove faces with file id
	 * with the current user in the DB
	 */
	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}

		$node = $event->getNode();
		if (!$this->fileService->isAllowedNode($node)) {
			// Nextcloud sends the Hooks when create thumbnails for example.
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		$modelId = $this->settingsService->getCurrentFaceModel();
		if ($modelId === SettingsService::FALLBACK_CURRENT_MODEL) {
			$this->logger->debug("Skipping deleting file since there are no configured model");
			return;
		}

		$owner = null;
		if ($this->fileService->isUserFile($node)) {
			$owner = $node->getOwner()->getUid();
		} else {
			if (!\OC::$server->getUserSession()->isLoggedIn()) {
				$this->logger->debug('Skipping deleting the file ' . $node->getName() .  ' since we cannot determine the owner');
				return;
			}
			$owner = \OC::$server->getUserSession()->getUser()->getUID();
		}

		$enabled = $this->settingsService->getUserEnabled($owner);
		if (!$enabled) {
			$this->logger->debug('The user ' . $owner . ' not have the analysis enabled. Skipping');
			return;
		}

		if ($node->getName() === FileService::NOMEDIA_FILE ||
		    $node->getName() === FileService::NOIMAGE_FILE) {
			// If user deleted file named .nomedia, that means all images in this and all child directories should be added.
			// But, instead of doing that here, better option seem to be to just reset flag that image scan is not done.
			// This will trigger another round of image crawling in AddMissingImagesTask for this user and those images will be added.
			$this->settingsService->setUserFullScanDone(false, $owner);
			return;
		}

		if ($node->getName() === FileService::FACERECOGNITION_SETTINGS_FILE) {
			// This file can enable or disable the analysis, so I have to look for new files and forget others.
			$this->settingsService->setNeedRemoveStaleImages(true, $owner);
			$this->settingsService->setUserFullScanDone(false, $owner);
			return;
		}

		if (!$this->settingsService->isAllowedMimetype($node->getMimeType())) {
			// The file is not an image or the model does not support it
			return;
		}

		$this->logger->debug("Deleting image " . $node->getName() . " from face recognition");

		$image = new Image();
		$image->setUser($owner);
		$image->setFile($node->getId());
		$image->setModel($modelId);

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

}

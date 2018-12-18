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
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUserManager;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
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

	/** @var IDBConnection */
	private $connection;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/**
	 * Watcher constructor.
	 *
	 * @param IConfig $config
	 * @param ILogger $logger
	 * @param IDBConnection $connection
	 * @param IUserManager $userManager
	 * @param FaceMapper $faceMapper
	 * @param ImageMapper $imageMapper
	 * @param PersonMapper $personMapper
	 */
	public function __construct(IConfig       $config,
	                            ILogger       $logger,
	                            IDBConnection $connection,
	                            IUserManager  $userManager,
	                            FaceMapper    $faceMapper,
	                            ImageMapper   $imageMapper,
	                            PersonMapper  $personMapper)
	{
		$this->config = $config;
		$this->logger = $logger;
		$this->connection = $connection;
		$this->userManager = $userManager;
		$this->faceMapper = $faceMapper;
		$this->imageMapper = $imageMapper;
		$this->personMapper = $personMapper;
	}

	/**
	 * A node has been updated. We just store the file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function postWrite(Node $node) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// FIXME: Can we know if it is a local or shared file?. See issue #73 on Github.
		// todo: should we also care about this too: instanceOfStorage(ISharedStorage::class);
		/*if ($node->getStorage()->instanceOfStorage(IHomeStorage::class) === false) {
			return;
		}*/

		if ($node instanceof Folder) {
			return;
		}

		// todo: issue #37 - if we detect .nomedia written, reset some global flag such that RemoveMissingImagesTask is triggered.

		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		// NOTE: The hooks also report the creation of thumbnails, and other files.
		// The way to differentiate between them, is that the user files starting with the userId, and the thumbains as appdata_ocs######
		$absPath = ltrim($node->getPath(), '/');
		$owner = explode('/', $absPath)[0];
		if (!$this->userManager->userExists($owner)) {
			return;
		}

		// If we detect .nomedia file anywhere on the path to root folder (id===null), bail out
		$parentNode = $node->getParent();
		while (($parentNode instanceof Folder) && ($parentNode->getId() !== null)) {
			if ($parentNode->nodeExists('.nomedia')) {
				$this->logger->debug(
					"Skipping inserting image " . $node->getName() . " because directory " . $parentNode->getName() . " contains .nomedia file");
				return;
			}

			$parentNode = $parentNode->getParent();
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
			$this->faceMapper->removeFaces($imageId);
		}
	}

	/**
	 * A node has been delete. Remove faces with file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function postDelete(Node $node) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// FIXME: Can we know if it is a local or shared file? See issue #73 on Github.
		// todo: should we also care about this too: instanceOfStorage(ISharedStorage::class);
		/*if ($node->getStorage()->instanceOfStorage(IHomeStorage::class) === false) {
			return;
		}*/

		if ($node instanceof Folder) {
			return;
		}

		if ($node->getName() === '.nomedia') {
			// If user deleted file named .nomedia, that means all images in this and all child directories should be added.
			// But, instead of doing that here, better option seem to be to just reset global flag that image scan is not done.
			// This will trigger another round of image crawling in AddMissingImagesTask and those images will be added.
			$this->config->setAppValue('facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
			return;
		}

		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		// NOTE: The hooks also report the creation of thumbnails, and other files.
		// The way to differentiate between them, is that the user files starting with the userId, and the thumbains as appdata_ocs######
		$absPath = ltrim($node->getPath(), '/');
		$owner = explode('/', $absPath)[0];
		if (!$this->userManager->userExists($owner)) {
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
			$this->faceMapper->removeFaces($imageId);

			$image->setId($imageId);
			$this->imageMapper->delete($image);
		}
	}
}

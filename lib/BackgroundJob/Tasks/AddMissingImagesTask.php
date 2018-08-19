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

use OCP\IDBConnection;
use OCP\IUser;
use OCP\Files\File;
use OCP\Files\Folder;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

/**
 * Task that, for each user, crawls for all images in filesystem and insert them in database.
 */
class AddMissingImagesTask extends FaceRecognitionBackgroundTask {
	/** @var IDBConnection DB connection */
	protected $connection;

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/**
	 * @param IDBConnection $connection DB connection
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(IDBConnection $connection, ImageMapper $imageMapper) {
		parent::__construct();
		$this->connection = $connection;
		$this->imageMapper = $imageMapper;
	}

	public function description() {
		return "Crawl for missing images for each user and insert them in DB";
	}

	public function do(FaceRecognitionContext $context) {
		$this->setContext($context);

		// Check if we are called for one user only, or for all user in instance.
		// todo: how to yield here?
		if (is_null($this->context->user)) {
			$this->context->userManager->callForSeenUsers(function (IUser $user) {
				$this->addMissingImagesForUser($user);
			});
		} else {
			$this->addMissingImagesForUser($this->context->user);
		}
	}

	/**
	 * Crawl filesystem for a given user
	 * TODO: duplicated from Queue.php, figure out how to merge
	 * (or delete this Queue.php when not needed)
	 *
	 * @param IUser $user User for which to crawl images for
	 */
	private function addMissingImagesForUser(IUser $user) {
		$this->logInfo(sprintf('Finding missing images for user %s', $user->getUID()));
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($user->getUID());

		$userFolder = $this->context->rootFolder->getUserFolder($user->getUID());
		$this->parseUserFolder($user, $userFolder);
	}

	/**
	 * Recursively crawls given folder for a given user
	 *
	 * @param IUser $user User for which we are crawling this folder for
	 * @param Folder $folder Folder to recursively search images in
	 */
	private function parseUserFolder(IUser $user, Folder $folder) {
		$nodes = $this->getPicturesFromFolder($folder);
		foreach ($nodes as $file) {
			$this->logDebug('Found ' . $file->getPath());
			// todo: replace 1 with model
			$dfgfd = $this->imageMapper->imageExists($user, $file);
			// todo: this check/insert logic for each image is so inefficient it hurts my mind
			if ($this->imageMapper->imageExists($user, $file, 1) == False) {
				$this->putImage($user, $file);
			}
		}
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
		$nodes = $folder->getDirectoryListing();

		foreach ($nodes as $node) {
			//if ($node->isHidden())
			//	continue;
			// $previewImage = new \OC_Image();
			// $previewImage->loadFromData($preview->getContent());
			if ($node instanceof Folder and !$node->nodeExists('.nomedia')) {
				$results = $this->getPicturesFromFolder($node, $results);
			} else if ($node instanceof File) {
				// todo: which are filetypes we can work with
				if ($node->getMimeType() === 'image/jpeg') {
					$results[] = $node;
				}
			}
		}

		return $results;
	}

	/**
	 * Adds found image to database.
	 * It doesn't check that this image already exists in database.
	 *
	 * @param IUser $user User for which to add this image to database
	 * @param File $file File (image) that should be added to database
	 */
	private function putImage(IUser $user, File $file) {
		$absPath = ltrim($file->getPath(), '/');
		$owner = explode('/', $absPath)[0];

		$image = new Image();
		$image->setUser($user->getUID());
		$image->setFile($file->getId());
		$image->setModel(1);
		// todo: can we have larger transaction with bulk insert?
		$this->imageMapper->insert($image);
	}
}
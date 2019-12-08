<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2019 Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Service;

use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\ITempManager;

use OCP\Files\IHomeStorage;
use OCP\Files\NotFoundException;

use OCA\Files_Sharing\External\Storage as SharingExternalStorage;

class FileService {

	/**  @var string|null */
	private $userId;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var ITempManager */
	private $tempManager;

	public function __construct($userId,
	                            IRootFolder  $rootFolder,
	                            ITempManager $tempManager)
	{
		$this->userId      = $userId;
		$this->rootFolder  = $rootFolder;
		$this->tempManager = $tempManager;
	}

	/**
	 * TODO: Describe exactly when necessary.
	 */
	public function setupFS(string $userId) {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($userId);

		$this->userId = $userId;
	}

	/**
	 * @return Node
	 * @throws NotFoundException
	 */
	public function getFileById($fileId, $userId = null): Node {
		$files = $this->rootFolder->getUserFolder($this->userId ?? $userId)->getById($fileId);
		if (count($files) === 0) {
			throw new NotFoundException();
		}

		return $files[0];
	}

	/**
	 * Checks if this file is located somewhere under .nomedia file and should be therefore ignored.
	 *
	 * @param File $file File to search for
	 * @return bool True if file is located under .nomedia, false otherwise
	 */
	public function isUnderNoMedia(Node $node): bool {
		// If we detect .nomedia file anywhere on the path to root folder (id===null), bail out
		$parentNode = $node->getParent();
		while (($parentNode instanceof Folder) && ($parentNode->getId() !== null)) {
			if ($parentNode->nodeExists('.nomedia')) {
				return true;
			}
			$parentNode = $parentNode->getParent();
		}
		return false;
	}

	/**
	 * Returns if the file is inside a shared storage.
	 */
	public function isSharedFile(Node $node): bool {
		return $node->getStorage()->instanceOfStorage(SharingExternalStorage::class);
	}

	/**
	 * Returns if the file is inside HomeStorage.
	 */
	public function isUserFile(Node $node): bool {
		return $node->getStorage()->instanceOfStorage(IHomeStorage::class);
	}

	/**
	 * Get a path to either the local file or temporary file
	 *
	 * @param File $file
	 * @param int $maxSize maximum size for temporary files
	 * @return string
	 */
	public function getLocalFile(File $file, int $maxSize = null): string {
		$useTempFile = $file->isEncrypted() || !$file->getStorage()->isLocal();
		if ($useTempFile) {
			$absPath = $this->tempManager->getTemporaryFile();

			$content = $file->fopen('r');
			if ($maxSize !== null) {
				$content = stream_get_contents($content, $maxSize);
			}
			file_put_contents($absPath, $content);

			return $absPath;
		} else {
			return $file->getStorage()->getLocalFile($file->getInternalPath());
		}
	}

	/**
	 * Create a temporary file and return the path
	 */
	public function getTemporaryFile(string $postFix = ''): string {
		return $this->tempManager->getTemporaryFile($postFix);
	}

	/**
	 * Remove any temporary file from the service.
	 */
	public function clean() {
		$this->tempManager->clean();
	}

}

<?php
/**
 * @copyright Copyright (c) 2018-2021 Matias De lellis <mati86dl@gmail.com>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Service;

use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\IURLGenerator;

use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Service\SettingsService;


class UrlService {

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IUserSession */
	private $userSession;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var SettingsService */
	private $settingsService;

	/** @var string */
	private $userId;

	/** */
	private $userFolder;

	public function __construct(IRootFolder     $rootFolder,
	                            IUserSession    $userSession,
	                            IURLGenerator   $urlGenerator,
	                            SettingsService $settingsService,
	                            $userId)
	{
		$this->rootFolder      = $rootFolder;
		$this->userSession     = $userSession;
		$this->urlGenerator    = $urlGenerator;
		$this->settingsService = $settingsService;
		$this->userId          = $userId;

		$this->userFolder      = $rootFolder->getUserFolder($userId);
	}


	/**
	 * Get to the Node file
	 *
	 * @param int $fileId file id to show
	 *
	 * @return null|File
	 */
	public function getFileNode(int $fileId): ?File {
		$files = $this->userFolder->getById($fileId);
		$file  = current($files);
		if(!($file instanceof File)) {
			// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
			$this->settingsService->setNeedRemoveStaleImages(true, $this->userId);
			return null;
		}
		return $file;
	}

	public function getBasename(File $node): string {
		return $node->getName();
	}

	public function getFilename(File $node): ?string {
		return $this->userFolder->getRelativePath($node->getPath());
	}

	public function getMimetype(File $node): string {
		return $node->getMimetype();
	}

	/**
	 * Get thumbnail of the give file id
	 *
	 * @param File $file file to show
	 * @param int $sideSize side lenght to show
	 */
	public function getPreviewUrl(File $file, int $sideSize): string {
		return $this->urlGenerator->getAbsoluteURL('index.php/core/preview?fileId=' . $file->getId() .'&x=' . $sideSize . '&y=' . $sideSize . '&a=false&v=' . $file->getETag());
	}

	/**
	 * Redirects to the file list and highlight the given file id
	 *
	 * @param int $fileId file id to show
	 *
	 * @return null|string
	 */
	public function getRedirectToFileUrl(File $file): ?string {
		$params = [];
		$params['dir'] = $this->userFolder->getRelativePath($file->getParent()->getPath());
		$params['scrollto'] = $file->getName();
		return $this->urlGenerator->linkToRoute('files.view.index', $params);
	}

	/**
	 * Redirects to the facerecognition page to show photos of an person.
	 *
	 * @param string $personName
	 *
	 * @return string
	 */
	public function getRedirectToPersonUrl(string $personName): string {
		if (Requirements::memoriesIsInstalled()) {
			return $this->urlGenerator->linkToRouteAbsolute('memories.Page.main') . 'facerecognition/' . $this->userId . '/' . $personName ;
		}
		$params = [
			'section' => 'facerecognition',
			'name' => $personName
		];
		return $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', $params);
	}

	/**
	 * Url to thumb face
	 *
	 * @param int $faceId face id to show
	 * @param int $size Size of face thumbnails
	 *
	 * @return string
	 */
	public function getThumbUrl(int $faceId, int $size): string {
		$params = [];
		$params['id'] = $faceId;
		$params['size'] = $size;
		return $this->urlGenerator->linkToRoute('facerecognition.face.getThumb', $params);
	}

	/**
	 * Url to thumb person
	 *
	 * @param string $name name person to show
	 * @param int $size Size of face thumbnails
	 *
	 * @return string
	 */
	public function getPersonThumbUrl(string $name, int $size): string {
		$params = [];
		$params['name'] = $name;
		$params['size'] = $size;
		return $this->urlGenerator->linkToRoute('facerecognition.face.getPersonThumb', $params);
	}

}

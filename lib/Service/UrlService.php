<?php
/**
 * @copyright Copyright (c) 2018-2020 Matias De lellis <mati86dl@gmail.com>
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

	public function __construct(IRootFolder     $rootFolder,
	                            IUserSession    $userSession,
	                            IURLGenerator   $urlGenerator,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		$this->rootFolder      = $rootFolder;
		$this->userSession     = $userSession;
		$this->urlGenerator    = $urlGenerator;
		$this->settingsService = $settingsService;
		$this->userId          = $UserId;
	}

	/**
	 * Url to thumb face
	 *
	 * @param int $faceId face id to show
	 * @param int $size Size of face thumbnails
	 */
	public function getThumbUrl(int $faceId, int $size) {
		$params = [];
		$params['id'] = $faceId;
		$params['size'] = $size;
		return $this->urlGenerator->linkToRoute('facerecognition.face.getThumb', $params);
	}

	/**
	 * Get thumbnail of the give file id
	 *
	 * @param int $fileId file id to show
	 * @param int $sideSize side lenght to show
	 */
	public function getPreviewUrl(int $fileId, int $sideSize): ?string {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$file = current($userFolder->getById($fileId));

		if (!($file instanceof File)) {
			// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
			$this->settingsService->setNeedRemoveStaleImages(true, $this->userId);
			return null;
		}

		return '/core/preview?fileId=' . $fileId .'&x=' . $sideSize . '&y=' . $sideSize . '&a=false&v=' . $file->getETag();
	}

	/**
	 * Redirects to the file list and highlight the given file id
	 *
	 * @param int $fileId file id to show
	 */
	public function getRedirectToFileUrl(int $fileId) {
		$uid        = $this->userSession->getUser()->getUID();
		$baseFolder = $this->rootFolder->getUserFolder($uid);
		$files      = $baseFolder->getById($fileId);
		$file       = current($files);

		if(!($file instanceof File)) {
			// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
			$this->settingsService->setNeedRemoveStaleImages(true, $this->userId);
			return null;
		}

		$params = [];
		$params['dir'] = $baseFolder->getRelativePath($file->getParent()->getPath());
		$params['scrollto'] = $file->getName();

		return $this->urlGenerator->linkToRoute('files.view.index', $params);
	}

	/**
	 * Redirects to the facerecognition page to show photos of an person.
	 *
	 * @param int $personId person id to show
	 */
	public function getRedirectToPersonUrl(string $personId) {
		$params = [
			'section' => 'facerecognition',
			'name' => $personId
		];
		return $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', $params);
	}

}

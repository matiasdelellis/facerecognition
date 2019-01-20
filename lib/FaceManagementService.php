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
namespace OCA\FaceRecognition;

use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;

/**
 * Background service. Both command and cron job are calling this service for long-running background operations.
 * Background processing for face recognition is comprised of several steps, called tasks. Each task is independent,
 * idempotent, DI-aware logic unit that yields. Since tasks are non-preemptive, they should yield from time to time, so we son't end up
 * working for more than given timeout.
 *
 * Tasks can be seen as normal sequential functions, but they are easier to work with,
 * reason about them and test them independently. Other than that, they are really glorified functions.
 */
class FaceManagementService {

	/** @var IConfig */
	private $config;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	public function __construct(IConfig $config, IUserManager $userManager,
			FaceMapper $faceMapper, ImageMapper $imageMapper, PersonMapper $personMapper) {
		$this->config = $config;
		$this->userManager = $userManager;
		$this->faceMapper = $faceMapper;
		$this->imageMapper = $imageMapper;
		$this->personMapper = $personMapper;
	}

	/**
	 * Deletes all faces, images and persons found. IF no user is given, resetting is executed for all users.
	 *
	 * @param IUser|null $user Optional user to execute resetting for
	 */
	public function resetAll(IUser $user = null) {
		$eligable_users = array();
		if (is_null($user)) {
			$this->userManager->callForAllUsers(function (IUser $user) use (&$eligable_users) {
				$eligable_users[] = $user->getUID();
			});
		} else {
			$eligable_users[] = $user->getUID();
		}

		foreach($eligable_users as $user) {
			$this->resetAllForUser($user);
		}
	}

	/**
	 * Deletes all faces, images and persons found for a given user.
	 *
	 * @param string $user ID of user to execute resetting for
	 */
	public function resetAllForUser(string $userId) {
		$this->faceMapper->deleteUserFaces($userId);
		$this->personMapper->deleteUserPersons($userId);
		$this->imageMapper->deleteUserImages($userId);
		$this->config->deleteUserValue($userId, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY);
	}
}
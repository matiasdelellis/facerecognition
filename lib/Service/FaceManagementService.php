<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2019, Branko Kokanovic <branko@kokanovic.org>
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

namespace OCA\FaceRecognition\Service;

use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;

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

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	public function __construct(IUserManager    $userManager,
	                            FaceMapper      $faceMapper,
	                            ImageMapper     $imageMapper,
	                            PersonMapper    $personMapper,
	                            SettingsService $settingsService)
	{
		$this->userManager     = $userManager;
		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
		$this->personMapper    = $personMapper;
		$this->settingsService = $settingsService;
	}

	/**
	 * Check if the current model has data on db
	 *
	 * @param IUser|null $user Optional user to check
	 * @param Int $modelId Optional model to check
	 */
	public function hasData(IUser $user = null, int $modelId = -1) {
		if ($modelId === -1) {
			$modelId = $this->settingsService->getCurrentFaceModel();
		}
		$eligible_users = $this->getEligiblesUserId($user);
		foreach ($eligible_users as $userId) {
			if ($this->hasDataForUser($userId, $modelId)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the current model has data on db for user
	 *
	 * @param string $user ID of user to check
	 * @param Int $modelId model to check
	 */
	public function hasDataForUser(string $userId, int $modelId) {
		$facesCount = $this->faceMapper->countFaces($userId, $modelId);
		return ($facesCount > 0);
	}


	/**
	 * Deletes all faces, images and persons found. IF no user is given, resetting is executed for all users.
	 *
	 * @param IUser|null $user Optional user to execute resetting for
	 */
	public function resetAll(IUser $user = null) {
		$eligible_users = $this->getEligiblesUserId($user);
		foreach($eligible_users as $user) {
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

		$this->settingsService->setUserFullScanDone(false, $userId);
	}

	/**
	 * Reset error in images in order to re-analyze again.
	 * If no user is given, resetting is executed for all users.
	 *
	 * @param IUser|null $user Optional user to execute resetting for
	 */
	public function resetImageErrors(IUser $user = null) {
		$eligible_users = $this->getEligiblesUserId($user);
		foreach($eligible_users as $userId) {
			$this->imageMapper->resetErrors($userId);
			$this->settingsService->setUserFullScanDone(false, $userId);
		}
	}

	/**
	 * Eliminate all faces relations with person.
	 * If no user is given, resetting is executed for all users.
	 *
	 * @param IUser|null $user Optional user to execute resetting for
	 */
	public function resetClusters(IUser $user = null) {
		$eligible_users = $this->getEligiblesUserId($user);
		foreach($eligible_users as $user) {
			$this->resetClustersForUser($user);
		}
	}

	/**
	 * Eliminate all faces relations with person.
	 *
	 * @param string $user ID of user to execute resetting for
	 */
	public function resetClustersForUser(string $userId) {
		$model = $this->settingsService->getCurrentFaceModel();

		$this->faceMapper->unsetPersonsRelationForUser($userId, $model);
		$this->personMapper->deleteUserPersons($userId);
	}

	/**
	 * Get an array with the eligibles users taking into account the user argument,
	 * or all users.
	 *
	 * @param IUser|null $user Optional user to get specific user.
	 */
	private function getEligiblesUserId(IUser $user = null): array {
		$eligible_users = array();
		if (is_null($user)) {
			$this->userManager->callForAllUsers(function (IUser $user) use (&$eligible_users) {
				$eligible_users[] = $user->getUID();
			});
		} else {
			$eligible_users[] = $user->getUID();
		}
		return $eligible_users;
	}

}
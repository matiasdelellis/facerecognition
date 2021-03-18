<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017-2021 Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Hooks;

use OCP\ILogger;
use OCP\IUserManager;

use OCA\FaceRecognition\Service\FaceManagementService;


class UserHooks {

	/** @var ILogger Logger */
	private $logger;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceManagementService */
	private $faceManagementService;

	/**
	 * Watcher constructor.
	 *
	 * @param ILogger $logger
	 * @param IUserManager $userManager
	 * @param FaceManagementService $faceManagementService
	 */
	public function __construct(ILogger               $logger,
	                            IUserManager          $userManager,
	                            FaceManagementService $faceManagementService)
	{
		$this->logger                = $logger;
		$this->userManager           = $userManager;
		$this->faceManagementService = $faceManagementService;
	}

	public function register() {
		// Watch for user deletion, so we clean up user data, after user gets deleted
		$this->userManager->listen('\OC\User', 'postDelete', function (\OC\User\User $user) {
			$this->postUserDelete($user);
		});
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

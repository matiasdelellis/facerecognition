<?php
/**
 * @copyright Copyright (c) 2019-2020 Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\BackgroundJob;

use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use \Psr\Log\LoggerInterface;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;

/**
 * Simple class holding all information that tasks might need, so they can do their job.
 * It can also serve as a temporary storage of information flowing from one task to another.
 */
class FaceRecognitionContext {
	/** @var IUserManager */
	public $userManager;

	/** @var IConfig */
	public $config;

	/** @var FaceRecognitionLogger */
	public $logger;

	/** @var LoggerInterface Reference to Nextcloud logger instance. This logger can be used to create messages that are shown in the Nextcloud log. See https://docs.nextcloud.com/server/28/developer_manual/basics/logging.html. */
	public $ncLogger;

	/** @var string Name of this application */
    public $appName;	

	/** @var IUser|null */
	public $user;

	/** @var bool */
	public $verbose;

	/** @var array Associative array that can hold various data from tasks */
	public $propertyBag = [];

	/** @var bool True if we are running from command, false if we are running as background job */
	private $isRunningThroughCommand;

	public function __construct(IUserManager 	$userManager,
								LoggerInterface $ncLogger, 
								string 	     	$appName,	
	                            IConfig      	$config) {
		$this->ncLogger = $ncLogger;
		$this->appName = $appName;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->isRunningThroughCommand = false;
	}

	public function getEligibleUsers(): array {
		$eligable_users = [];
		if (!is_null($this->user)) {
			$eligable_users[] = $this->user->getUID();
		} else {
			$this->userManager->callForSeenUsers(function (IUser $user) use (&$eligable_users) {
				$eligable_users[] = $user->getUID();
			});
		}
		return $eligable_users;
	}

	public function isRunningInSyncMode(): bool {
		if ((array_key_exists('run_mode', $this->propertyBag)) &&
		    (!is_null($this->propertyBag['run_mode']))) {
			return ($this->propertyBag['run_mode'] === 'sync-mode');
		}
		return false;
	}

	public function isRunningThroughCommand(): bool {
		return $this->isRunningThroughCommand;
	}

	public function setRunningThroughCommand(): void {
		$this->isRunningThroughCommand = true;
	}
}
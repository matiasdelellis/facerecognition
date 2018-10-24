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
namespace OCA\FaceRecognition\BackgroundJob;

use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;

/**
 * Simple class holding all information that tasks might need, so they can do their job.
 * It can also serve as a temporary storage of information flowing from one task to another.
 */
class FaceRecognitionContext {
	/** @var IAppManager */
	public $appManager;

	/** @var IUserManager */
	public $userManager;

	/** @var IRootFolder */
	public $rootFolder;

	/** @var IConfig */
	public $config;

	/** @var FaceRecognitionLogger */
	public $logger;

	/** @var IUser|null */
	public $user;

	/** @var bool */
	public $verbose;

	/** @var array Associative array that can hold various data from tasks */
	public $propertyBag = [];

	/** @var bool True if we are running from command, false if we are running as background job */
	private $isRunningThroughCommand;

	public function __construct(IAppManager $appManager, IUserManager $userManager, IRootFolder $rootFolder, IConfig $config) {
		$this->appManager = $appManager;
		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->isRunningThroughCommand = false;
	}

	public function isRunningThroughCommand(): bool {
		return $this->isRunningThroughCommand;
	}

	public function setRunningThroughCommand() {
		$this->isRunningThroughCommand = true;
	}
}
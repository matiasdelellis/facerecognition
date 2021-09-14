<?php
/**
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
namespace OCA\FaceRecognition\Migration;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;

class RemoveFullImageScanDoneFlag implements IRepairStep {

	/** @var IConfig Config */
	private $config;

	/** @var IUserManager */
	private $userManager;

	/**
	 * RemoveFullImageScanDoneFlag constructor.
	 *
	 * @param IConfig $config
	 * @param IUserManager $userManager
	 */
	public function __construct(IConfig $config, IUserManager $userManager) {
		$this->config = $config;
		$this->userManager = $userManager;
	}

	/**
	 * @inheritdoc
	 */
	public function getName() {
		return 'Removes ' . AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY . ' flag for all users, so that new installation can crawl over all images again';
	}

	/**
	 * @inheritdoc
	 *
	 * @return void
	 */
	public function run(IOutput $output) {
		$this->userManager->callForSeenUsers(function (IUser $user) {
			$this->config->deleteUserValue($user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY);
		});
	}
}
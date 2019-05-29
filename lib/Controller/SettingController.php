<?php
/**
 * @copyright Copyright (c) 2019, Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Controller;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\FaceManagementService;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;

class SettingController extends Controller {

	/** @var IConfig */
	private $config;

	/** @var IUserManager */
	private $userManager;

	/** @var string */
	private $userId;

	const STATE_OK = 0;
	const STATE_FALSE = 1;
	const STATE_SUCCESS = 2;
	const STATE_ERROR = 3;

	public function __construct ($appName,
	                             IRequest     $request,
	                             IConfig      $config,
	                             IUserManager $userManager,
	                             $userId)
	{
		parent::__construct($appName, $request);
		$this->appName               = $appName;
		$this->config                = $config;
		$this->userManager           = $userManager;
		$this->userId                = $userId;
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 */
	public function setUserValue($type, $value) {
		// Apply the change of settings
		$this->config->setUserValue($this->userId, $this->appName, $type, $value);

		// Handles special cases when have to do something else according to the change
		switch ($type) {
			case 'enabled':
				if ($value === 'true') {
					$this->config->setUserValue($this->userId, $this->appName, AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
				}
				break;
			default:
				break;
		}

		// Response
		$result = [
			'status' => self::STATE_SUCCESS,
			'value' => $value
		];
		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @return JSONResponse
	 */
	public function getUserValue($type) {
		$value = $this->config->getUserValue($this->userId, $this->appName, $type);
		if ($value !== '') {
			$result = [
				'status' => self::STATE_OK,
				'value' => $value
			];
		} else {
			$result = [
				'status' => self::STATE_FALSE,
				'value' =>'nodata'
			];
		}
		return new JSONResponse($result);
	}

	/**
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 */
	public function setAppValue($type, $value) {
		$status = self::STATE_SUCCESS;
		switch ($type) {
			case 'sensitivity':
				$this->config->setAppValue('facerecognition', $type, $value);
				$this->userManager->callForSeenUsers(function(IUser $user) {
					$this->config->setUserValue($user->getUID(), 'facerecognition', 'recreate-clusters', 'true');
				});
				break;
			case 'memory-limits':
				if (is_numeric ($value)) {
					// Apply prundent limits.
					if ($value < 1 * 1024 * 1024 * 1024) {
						$value = 1 * 1024 * 1024 * 1024;
						$status = self::STATE_ERROR;
					} else if ($value > 4 * 1024 * 1024 * 1024) {
						$value = 4 * 1024 * 1024;
						$status = self::STATE_ERROR;
					}
					// Valid according to RAM of php.ini setting.
					$memory = MemoryLimits::getAvailableMemory();
					if ($value > $memory) {
						$value = $memory;
						$status = self::STATE_ERROR;
					}
					// If any validation error saves the value
					if ($status !== self::STATE_ERROR)
						$this->config->setAppValue('facerecognition', $type, $value);
				} else {
					$status = self::STATE_ERROR;
					$value = '-1';
				}
				break;
			default:
				break;
		}

		// Response
		$result = [
			'status' => $status,
			'value' => $value
		];
		return new JSONResponse($result);
	}

	/**
	 * @param $type
	 * @return JSONResponse
	 */
	public function getAppValue($type) {
		$value = 'nodata';
		$status = self::STATE_OK;
		switch ($type) {
			case 'sensitivity':
				$value = $this->config->getAppValue('facerecognition', $type, '0.5');
				break;
			case 'memory-limits':
				$value = $this->config->getAppValue('facerecognition', $type, '-1');
				// If it was not configured, returns the default
				// values used by the background task as a reference.
				if ($value === '-1') {
					$memory = MemoryLimits::getAvailableMemory();
					if ($memory > 4 * 1024 * 1024 * 1024)
						$memory = 4 * 1024 * 1024 * 1024;
					$value = $memory;
					$status = self::STATE_FALSE;
				}
				break;
			default:
				break;
		}

		// Response
		$result = [
			'status' => $status,
			'value' => $value
		];

		return new JSONResponse($result);
	}

}

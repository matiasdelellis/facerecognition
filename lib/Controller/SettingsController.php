<?php
/**
 * @copyright Copyright (c) 2019-2020 Matias De lellis <mati86dl@gmail.com>
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

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IL10N;

use OCA\FaceRecognition\Helper\MemoryLimits;
use OCA\FaceRecognition\Service\SettingsService;

class SettingsController extends Controller {

	/** @var SettingsService */
	private $settingsService;

	/** @var \OCP\IL10N */
	protected $l10n;

	/** @var IUserManager */
	private $userManager;

	/** @var string */
	private $userId;

	const STATE_OK = 0;
	const STATE_FALSE = 1;
	const STATE_SUCCESS = 2;
	const STATE_ERROR = 3;

	public function __construct ($appName,
	                             IRequest        $request,
	                             SettingsService $settingsService,
	                             IL10N           $l10n,
	                             IUserManager    $userManager,
	                             $userId)
	{
		parent::__construct($appName, $request);

		$this->appName         = $appName;
		$this->settingsService = $settingsService;
		$this->l10n            = $l10n;
		$this->userManager     = $userManager;
		$this->userId          = $userId;
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 */
	public function setUserValue($type, $value) {
		$status = self::STATE_SUCCESS;
		switch ($type) {
			case 'enabled':
				$enabled = ($value === 'true');
				$this->settingsService->setUserEnabled($enabled);
				if ($enabled) {
					$this->settingsService->setUserFullScanDone(false);
				}
				break;
			default:
				$status = self::STATE_ERROR;
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
	 * @NoAdminRequired
	 * @param $type
	 * @return JSONResponse
	 */
	public function getUserValue($type) {
		$status = self::STATE_OK;
		$value ='nodata';
		switch ($type) {
			case 'enabled':
				$value = $this->settingsService->getUserEnabled();
				break;
			default:
				$status = self::STATE_FALSE;
				break;
		}
		$result = [
			'status' => $status,
			'value' => $value
		];
		return new JSONResponse($result);
	}

	/**
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 */
	public function setAppValue($type, $value) {
		$status = self::STATE_SUCCESS;
		$message = "";
		switch ($type) {
			case 'sensitivity':
				$this->settingsService->setSensitivity ($value);
				$this->userManager->callForSeenUsers(function(IUser $user) {
					$this->settingsService->setNeedRecreateClusters(true, $user->getUID());
				});
				break;
			case 'min-confidence':
				$this->settingsService->setMinimumConfidence ($value);
				$this->userManager->callForSeenUsers(function(IUser $user) {
					$this->settingsService->setNeedRecreateClusters(true, $user->getUID());
				});
				break;
			case 'memory-limits':
				if (!is_numeric ($value)) {
					$status = self::STATE_ERROR;
					$message = $this->l10n->t("The format seems to be incorrect.");
					$value = '-1';
				}
				// Apply prundent limits.
				if ($value < SettingsService::MINIMUM_MEMORY_LIMITS) {
					$value = SettingsService::MINIMUM_MEMORY_LIMITS;
					$message = $this->l10n->t("Recommended memory for analysis is at least 1GB of RAM.");
					$status = self::STATE_ERROR;
				} else if ($value > SettingsService::MAXIMUM_MEMORY_LIMITS) {
					$value = SettingsService::MAXIMUM_MEMORY_LIMITS;
					$message = $this->l10n->t("We are not recommending using more than 4GB of RAM memory, as we see little to no benefit.");
					$status = self::STATE_ERROR;
				}
				// Valid according to RAM of php.ini setting.
				$memory = MemoryLimits::getAvailableMemory();
				if ($value > $memory) {
					$value = $memory;
					$message = $this->l10n->t("According to your system you can not use more than %s GB of RAM", ($value / 1024 / 1024 / 1024));
					$status = self::STATE_ERROR;
				}
				// If any validation error saves the value
				if ($status !== self::STATE_ERROR) {
					$message = $this->l10n->t("The changes were saved. It will be taken into account in the next analysis.");
					$this->settingsService->setMemoryLimits($value);
				}
				break;
			case 'show-not-grouped':
				$this->settingsService->setShowNotGrouped($value == 'true' ? true : false);
				break;
			default:
				break;
		}

		// Response
		$result = [
			'status' => $status,
			'message' => $message,
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
				$value = $this->settingsService->getSensitivity();
				break;
			case 'min-confidence':
				$value = $this->settingsService->getMinimumConfidence();
				break;
			case 'memory-limits':
				$value = $this->settingsService->getMemoryLimits();
				// If it was not configured, returns the default
				// values used by the background task as a reference.
				if ($value == SettingsService::DEFAULT_MEMORY_LIMITS) {
					$memory = MemoryLimits::getAvailableMemory();
					if ($memory > SettingsService::MAXIMUM_MEMORY_LIMITS)
						$memory = SettingsService::MAXIMUM_MEMORY_LIMITS;
					$value = $memory;
					$status = self::STATE_FALSE;
				}
				break;
			case 'show-not-grouped':
				$value = $this->settingsService->getShowNotGrouped();
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

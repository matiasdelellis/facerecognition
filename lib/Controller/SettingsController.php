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

use OCP\Util as OCP_Util;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\SettingsService;

class SettingsController extends Controller {

	/** @var ModelManager */
	private $modelManager;

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
	                             ModelManager    $modelManager,
	                             SettingsService $settingsService,
	                             IL10N           $l10n,
	                             IUserManager    $userManager,
	                             $userId)
	{
		parent::__construct($appName, $request);

		$this->appName         = $appName;
		$this->modelManager    = $modelManager;
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
			case SettingsService::USER_ENABLED_KEY:
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
			case SettingsService::USER_ENABLED_KEY:
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
			case SettingsService::ANALYSIS_IMAGE_AREA_KEY:
				if (!is_numeric ($value)) {
					$status = self::STATE_ERROR;
					$message = $this->l10n->t("The format seems to be incorrect.");
					$value = '-1';
					break;
				}
				$model = $this->modelManager->getCurrentModel();
				if (is_null($model)) {
					$status = self::STATE_ERROR;
					$message = $this->l10n->t("Seems you haven't set up any analysis model yet");
					$value = '-1';
					break;
				}
				// Apply prudent limits.
				if ($value > 0 && $value < SettingsService::MINIMUM_ANALYSIS_IMAGE_AREA) {
					$value = SettingsService::MINIMUM_ANALYSIS_IMAGE_AREA;
					$message = $this->l10n->t("The minimum recommended area is %s", $this->getFourByThreeRelation($value));
					$status = self::STATE_ERROR;
				} else if ($value > SettingsService::MAXIMUM_ANALYSIS_IMAGE_AREA) {
					$value = SettingsService::MAXIMUM_ANALYSIS_IMAGE_AREA;
					$message = $this->l10n->t("The maximum recommended area is %s", $this->getFourByThreeRelation($value));
					$status = self::STATE_ERROR;
				}
				$model->open();
				$maxImageArea = $model->getMaximumArea();
				if ($value > $maxImageArea) {
					$value = $maxImageArea;
					$message = $this->l10n->t("The model does not recommend an area greater than %s", $this->getFourByThreeRelation($value));
					$status = self::STATE_ERROR;
				}
				// If any validation error saves the value
				if ($status !== self::STATE_ERROR) {
					$message = $this->l10n->t("The changes were saved. It will be taken into account in the next analysis.");
					$this->settingsService->setAnalysisImageArea((int) $value);
				}
				break;
			case SettingsService::SENSITIVITY_KEY:
				$this->settingsService->setSensitivity($value);
				$this->userManager->callForSeenUsers(function(IUser $user) {
					$this->settingsService->setNeedRecreateClusters(true, $user->getUID());
				});
				break;
			case SettingsService::MINIMUM_CONFIDENCE_KEY:
				$this->settingsService->setMinimumConfidence($value);
				$this->userManager->callForSeenUsers(function(IUser $user) {
					$this->settingsService->setNeedRecreateClusters(true, $user->getUID());
				});
				break;
			case SettingsService::MINIMUM_FACES_IN_CLUSTER_KEY:
				$this->settingsService->setMinimumFacesInCluster($value);
				break;
			case SettingsService::OBFUSCATE_FACE_THUMBS_KEY:
				$this->settingsService->setObfuscateFaces(!$this->settingsService->getObfuscateFaces());
				break;
			default:
				$status = self::STATE_ERROR;
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
		$status = self::STATE_OK;
		$value = 'nodata';

		switch ($type) {
			case SettingsService::SENSITIVITY_KEY:
				$value = $this->settingsService->getSensitivity();
				break;
			case SettingsService::MINIMUM_CONFIDENCE_KEY:
				$value = $this->settingsService->getMinimumConfidence();
				break;
			case SettingsService::MINIMUM_FACES_IN_CLUSTER_KEY:
				$value = $this->settingsService->getMinimumFacesInCluster();
				break;
			case SettingsService::ANALYSIS_IMAGE_AREA_KEY:
				$value = $this->settingsService->getAnalysisImageArea();
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
	 * Get an approximate image size with 4x3 ratio
	 * @param int $area area in pixels^2
	 * @return string
	 */
	private function getFourByThreeRelation(int $area): string {
		$width = intval(sqrt($area * 4 / 3));
		$height = intval($width * 3  / 4);
		return $width . 'x' . $height . ' (4x3)';
	}

}

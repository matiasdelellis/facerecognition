<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Matias De lellis <mati86dl@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Service;

use OCA\FaceRecognition\AppInfo\Application;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

use OCP\IConfig;

class SettingService {

	/*
	 * Settings keys and default values.
	 */

	/** Current Model used to analyze */
	const CURRENT_MODEL_KEY = 'model';
	/* Default values is taked from AddDefaultFaceModel */

	/** Sensitivity used to clustering */
	const SENSITIVITY_KEY = 'sensitivity';
	const MINIMUM_SENSITIVITY = '0.4';
	const DEFAULT_SENSITIVITY = '0.5';
	const MAXIMUM_SENSITIVITY = '0.6';

	/** Minimum confidence used to try to clustring faces */
	const MINIMUM_CONFIDENCE_KEY = 'min-confidence';
	const MINIMUM_MINIMUM_CONFIDENCE = '0.0';
	const DEFAULT_MINIMUM_CONFIDENCE = '0.9';
	const MAXIMUM_MINIMUM_CONFIDENCE = '1.0';

	/** Memory limit suggested for analysis */
	const MEMORY_LIMITS_KEY = "memory-limits";
	const MINIMUM_MEMORY_LIMITS = 1 * 1024 * 1024 * 1024;
	const DEFAULT_MEMORY_LIMITS = '-1'; // It is dynamically configured according to hardware
	const MAXIMUM_MEMORY_LIMITS = 4 * 1024 * 1024 * 1024;

	/** Show single persons on clustes view */
	const SHOW_NOT_GROUPED_KEY = 'show-not-grouped';
	const DEFAULT_SHOW_NOT_GROUPED = 'false';

	/** User setting what indicates if has the analysis enabled */
	const USER_ENABLED_KEY = 'enabled';
	const DEFAULT_USER_ENABLED = 'false';

	/** User setting that remember last images checked */
	const STALE_IMAGES_LAST_CHECKED_KEY = 'stale_images_last_checked';
	const DEFAULT_STALE_IMAGES_LAST_CHECKED = '0';

	/** Define if for some reason need remove old images */
	const STALE_IMAGES_REMOVAL_NEEDED_KEY = 'stale_images_removal_needed';
	const DEFAULT_STALE_IMAGES_REMOVAL_NEEDED = 'false';

	/** User setting that indicate when scan finished */
	const FULL_IMAGE_SCAN_DONE_KEY = 'full_image_scan_done';
	const DEFAULT_FULL_IMAGE_SCAN_DONE = 'false';

	/** User setting that indicate that need to recreate clusters */
	const USER_RECREATE_CLUSTERS_KEY = 'recreate-clusters';
	const DEFAULT_USER_RECREATE_CLUSTERS = 'false';

	/** @var IConfig Config */
	private $config;

	/**  @var string|null */
	private $userId;

	/**
	 * @param IConfig $config
	 * @param string $userId
	 */
	public function __construct(IConfig $config,
	                            $userId)
	{
		$this->config = $config;
		$this->userId = $userId;
	}

	/*
	 * User settings.
	 */
	public function getUserEnabled ($userId = null): bool {
		$enabled = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_ENABLED_KEY, self::DEFAULT_USER_ENABLED);
		return ($enabled === 'true');
	}

	public function setUserEnabled (bool $enabled, $userId = null) {
		 $this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_ENABLED_KEY, $enabled ? "true" : "false");
	}

	public function getUserFullScanDone ($userId = null): bool {
		$fullScanDone = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::FULL_IMAGE_SCAN_DONE_KEY, self::DEFAULT_FULL_IMAGE_SCAN_DONE);
		return ($fullScanDone === 'true');
	}

	public function setUserFullScanDone (bool $fullScanDone, $userId = null) {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_ENABLED_KEY, $fullScanDone ? "true" : "false");
	}

	public function getNeedRecreateClusters ($userId = null): bool {
		$needRecreate = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_RECREATE_CLUSTERS_KEY, self::DEFAULT_USER_RECREATE_CLUSTERS);
		return ($needRecreate === 'true');
	}

	public function setNeedRecreateClusters (bool $needRecreate, $userId = null) {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_RECREATE_CLUSTERS_KEY, $needRecreate ? "true" : "false");
	}

	/*
	 * Admin and process settings.
	 */
	public function getCurrentFaceModel(): int {
		return intval($this->config->getAppValue(Application::APP_NAME, self::CURRENT_MODEL_KEY, AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));
	}

	public function setCurrentFaceModel($model) {
		$this->config->setAppValue(Application::APP_NAME, self::CURRENT_MODEL_KEY, $model);
	}

	public function getSensitivity(): float {
		return floatval($this->config->getAppValue(Application::APP_NAME, self::SENSITIVITY_KEY, self::DEFAULT_SENSITIVITY));
	}

	public function setSensitivity($sensitivity) {
		$this->config->setAppValue(Application::APP_NAME, self::SENSITIVITY_KEY, $sensitivity);
	}

	public function getMinimumConfidence(): float {
		return floatval($this->config->getAppValue(Application::APP_NAME, self::MINIMUM_CONFIDENCE_KEY, self::DEFAULT_MINIMUM_CONFIDENCE));
	}

	public function setMinimumConfidence($confidence) {
		$this->config->setAppValue(Application::APP_NAME, self::MINIMUM_CONFIDENCE_KEY, $confidence);
	}

	public function getMemoryLimits(): int {
		return intval($this->config->getAppValue(Application::APP_NAME, self::MEMORY_LIMITS_KEY, self::DEFAULT_MEMORY_LIMITS));
	}

	public function setMemoryLimits(int $memoryLimits) {
		$this->config->setAppValue(Application::APP_NAME, self::MEMORY_LIMITS_KEY, strval($memoryLimits));
	}

	public function getShowNotGrouped (): bool {
		$show = $this->config->getAppValue(Application::APP_NAME, self::SHOW_NOT_GROUPED_KEY, self::DEFAULT_SHOW_NOT_GROUPED);
		return ($show === 'true');
	}

	public function setShowNotGrouped (bool $show) {
		 $this->config->setAppValue(Application::APP_NAME, self::SHOW_NOT_GROUPED_KEY, $show ? "true" : "false");
	}

}

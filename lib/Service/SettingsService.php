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

use OCA\FaceRecognition\Model\ModelManager;

use OCP\IConfig;

class SettingsService {

	/*
	 * System
	 */
	const MINIMUM_SYSTEM_MEMORY_REQUIREMENTS = 2 * 1024 * 1024 * 1024;

	/*
	 * Settings keys and default values.
	 */

	/** Current Model used to analyze */
	const CURRENT_MODEL_KEY = 'model';
	const FALLBACK_CURRENT_MODEL = -1;

	/** Image area that used used for analysis */
	const ANALYSIS_IMAGE_AREA_KEY = 'analysis_image_area';
	const MINIMUM_ANALYSIS_IMAGE_AREA = 640*480;
	const DEFAULT_ANALYSIS_IMAGE_AREA = -1; // It is dynamically configured according to hardware
	const MAXIMUM_ANALYSIS_IMAGE_AREA = 3840*2160;

	/** Sensitivity used to clustering */
	const SENSITIVITY_KEY = 'sensitivity';
	const MINIMUM_SENSITIVITY = '0.2';
	const DEFAULT_SENSITIVITY = '0.4';
	const MAXIMUM_SENSITIVITY = '0.6';

	/** Deviation used to suggestions */
	const DEVIATION_KEY = 'deviation';
	const MINIMUM_DEVIATION = '0.0';
	const DEFAULT_DEVIATION = '0.0';
	const MAXIMUM_DEVIATION = '0.2';

	/** Minimum confidence used to try to clustring faces */
	const MINIMUM_CONFIDENCE_KEY = 'min_confidence';
	const MINIMUM_MINIMUM_CONFIDENCE = '0.0';
	const DEFAULT_MINIMUM_CONFIDENCE = '0.99';
	const MAXIMUM_MINIMUM_CONFIDENCE = '1.1';

	/** Show single persons on clustes view */
	const SHOW_NOT_GROUPED_KEY = 'show_not_grouped';
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
	const USER_RECREATE_CLUSTERS_KEY = 'recreate_clusters';
	const DEFAULT_USER_RECREATE_CLUSTERS = 'false';

	/** User setting that indicate that is forced to create clusters */
	const FORCE_CREATE_CLUSTERS_KEY = 'force_create_clusters';
	const DEFAULT_FORCE_CREATE_CLUSTERS = 'false';

	/** Hidden setting that allows to analyze shared files */
	const HANDLE_SHARED_FILES_KEY = 'handle_shared_files';
	const DEFAULT_HANDLE_SHARED_FILES = 'false';

	/** Hidden setting that allows to analyze external files */
	const HANDLE_EXTERNAL_FILES_KEY = 'handle_external_files';
	const DEFAULT_HANDLE_EXTERNAL_FILES = 'false';

	/** Hidden setting that indicate minimum large of image to analyze */
	const MINIMUM_IMAGE_SIZE_KEY = 'min_image_size';
	const DEFAULT_MINIMUM_IMAGE_SIZE = '512';

	/** Hidden setting that indicate maximum area of image to analyze */
	const MAXIMUM_IMAGE_AREA_KEY = 'max_image_area';
	const DEFAULT_MAXIMUM_IMAGE_AREA = '-1';

	/** Hidden setting that allows obfuscate that faces for security */
	const OBFUSCATE_FACE_THUMBS_KEY = 'obfuscate_faces';
	const DEFAULT_OBFUSCATE_FACE_THUMBS = 'false';

	/** System setting to enable mimetypes */
	const SYSTEM_ENABLED_MIMETYPES = 'enabledFaceRecognitionMimetype';
	private $allowedMimetypes = ['image/jpeg', 'image/png'];
	private $cachedAllowedMimetypes = false;

	/**
	 * SettingsService
	 */

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
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::FULL_IMAGE_SCAN_DONE_KEY, $fullScanDone ? "true" : "false");
	}

	public function getNeedRemoveStaleImages ($userId = null): bool {
		$needRemoval = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_REMOVAL_NEEDED_KEY, self::DEFAULT_STALE_IMAGES_REMOVAL_NEEDED);
		return ($needRemoval === 'true');
	}

	public function setNeedRemoveStaleImages (bool $needRemoval, $userId = null) {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_REMOVAL_NEEDED_KEY, $needRemoval ? "true" : "false");
	}

	public function getLastStaleImageChecked ($userId = null): int {
		return intval($this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_LAST_CHECKED_KEY, self::DEFAULT_STALE_IMAGES_LAST_CHECKED));
	}

	public function setLastStaleImageChecked (int $lastCheck, $userId = null) {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_LAST_CHECKED_KEY, $lastCheck);
	}

	public function getNeedRecreateClusters ($userId = null): bool {
		$needRecreate = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_RECREATE_CLUSTERS_KEY, self::DEFAULT_USER_RECREATE_CLUSTERS);
		return ($needRecreate === 'true');
	}

	public function setNeedRecreateClusters (bool $needRecreate, $userId = null) {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_RECREATE_CLUSTERS_KEY, $needRecreate ? "true" : "false");
	}

	public function getForceCreateClusters ($userId = null): bool {
		$forceCreate = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::FORCE_CREATE_CLUSTERS_KEY, self::DEFAULT_FORCE_CREATE_CLUSTERS);
		return ($forceCreate === 'true');
	}

	public function setForceCreateClusters (bool $forceCreate, $userId = null) {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::FORCE_CREATE_CLUSTERS_KEY, $forceCreate ? "true" : "false");
	}

	/*
	 * Admin and process settings.
	 */
	public function getCurrentFaceModel(): int {
		return intval($this->config->getAppValue(Application::APP_NAME, self::CURRENT_MODEL_KEY, self::FALLBACK_CURRENT_MODEL));
	}

	public function setCurrentFaceModel(int $model) {
		$this->config->setAppValue(Application::APP_NAME, self::CURRENT_MODEL_KEY, strval($model));
	}

	public function getAnalysisImageArea(): int {
		return intval($this->config->getAppValue(Application::APP_NAME, self::ANALYSIS_IMAGE_AREA_KEY, self::DEFAULT_ANALYSIS_IMAGE_AREA));
	}

	public function setAnalysisImageArea(int $imageArea) {
		$this->config->setAppValue(Application::APP_NAME, self::ANALYSIS_IMAGE_AREA_KEY, strval($imageArea));
	}

	public function getSensitivity(): float {
		return floatval($this->config->getAppValue(Application::APP_NAME, self::SENSITIVITY_KEY, self::DEFAULT_SENSITIVITY));
	}

	public function setSensitivity($sensitivity) {
		$this->config->setAppValue(Application::APP_NAME, self::SENSITIVITY_KEY, $sensitivity);
	}

	public function getDeviation(): float {
		return floatval($this->config->getAppValue(Application::APP_NAME, self::DEVIATION_KEY, self::DEFAULT_DEVIATION));
	}

	public function setDeviation($deviation) {
		$this->config->setAppValue(Application::APP_NAME, self::DEVIATION_KEY, $deviation);
	}

	public function getMinimumConfidence(): float {
		return floatval($this->config->getAppValue(Application::APP_NAME, self::MINIMUM_CONFIDENCE_KEY, self::DEFAULT_MINIMUM_CONFIDENCE));
	}

	public function setMinimumConfidence($confidence) {
		$this->config->setAppValue(Application::APP_NAME, self::MINIMUM_CONFIDENCE_KEY, $confidence);
	}

	public function getShowNotGrouped(): bool {
		$show = $this->config->getAppValue(Application::APP_NAME, self::SHOW_NOT_GROUPED_KEY, self::DEFAULT_SHOW_NOT_GROUPED);
		return ($show === 'true');
	}

	public function setShowNotGrouped(bool $show) {
		$this->config->setAppValue(Application::APP_NAME, self::SHOW_NOT_GROUPED_KEY, $show ? "true" : "false");
	}

	/**
	 * The next settings are advanced preferences that are not available in gui.
	 * See: https://github.com/matiasdelellis/facerecognition/wiki/Settings#hidden-settings
	 */
	public function getHandleSharedFiles(): bool {
		$handle = $this->config->getAppValue(Application::APP_NAME, self::HANDLE_SHARED_FILES_KEY, self::DEFAULT_HANDLE_SHARED_FILES);
		return ($handle === 'true');
	}

	public function getHandleExternalFiles(): bool {
		$handle = $this->config->getAppValue(Application::APP_NAME, self::HANDLE_EXTERNAL_FILES_KEY, self::DEFAULT_HANDLE_EXTERNAL_FILES);
		return ($handle === 'true');
	}

	public function getMinimumImageSize(): int {
		return intval($this->config->getAppValue(Application::APP_NAME, self::MINIMUM_IMAGE_SIZE_KEY, self::DEFAULT_MINIMUM_IMAGE_SIZE));
	}

	public function getMaximumImageArea(): int {
		return intval($this->config->getAppValue(Application::APP_NAME, self::MAXIMUM_IMAGE_AREA_KEY, self::DEFAULT_MAXIMUM_IMAGE_AREA));
	}

	public function getObfuscateFaces(): bool {
		$obfuscate = $this->config->getAppValue(Application::APP_NAME, self::OBFUSCATE_FACE_THUMBS_KEY, self::DEFAULT_OBFUSCATE_FACE_THUMBS);
		return ($obfuscate === 'true');
	}

	public function setObfuscateFaces(bool $obfuscate) {
		$this->config->setAppValue(Application::APP_NAME, self::OBFUSCATE_FACE_THUMBS_KEY, $obfuscate ? 'true' : 'false');
	}

	/**
	 * System settings that must be configured according to the server configuration.
	 */
	public function isAllowedMimetype(string $mimetype): bool {
		if (!$this->cachedAllowedMimetypes) {
			$systemMimetypes = $this->config->getSystemValue(self::SYSTEM_ENABLED_MIMETYPES, $this->allowedMimetypes);
			$this->allowedMimetypes = array_merge($this->allowedMimetypes, $systemMimetypes);
			$this->allowedMimetypes = array_unique($this->allowedMimetypes);

			$this->cachedAllowedMimetypes = true;
		}

		return in_array($mimetype, $this->allowedMimetypes);
	}

}

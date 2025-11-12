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
use OCP\IAppConfig;

class SettingsService {

	/*
	 * System
	 */
	const MINIMUM_SYSTEM_MEMORY_REQUIREMENTS = 1 * 1024 * 1024 * 1024;

	/*
	 * Settings keys and default values.
	 */

	/** Current Model used to analyze */
	const CURRENT_MODEL_KEY = 'model';
	const FALLBACK_CURRENT_MODEL = -1;

	/* Enabling facial recognition for users */
	const DEFAULT_USER_ENABLED_KEY = 'default_enabled';
	const DEFAULT_USER_ENABLED = false;

	/* Assigned memory for image processing */
	const ASSIGNED_MEMORY_KEY = 'assigned_memory';
	const MINIMUM_ASSIGNED_MEMORY = (1 * 1024 * 1024 * 1024) * 2.0 / 3.0;
	const DEFAULT_ASSIGNED_MEMORY = -1;
	const MAXIMUN_ASSIGNED_MEMORY = 8 * 1024 * 1024 * 1024;

	/** Image area that used used for analysis */
	const ANALYSIS_IMAGE_AREA_KEY = 'analysis_image_area';
	const MINIMUM_ANALYSIS_IMAGE_AREA = 640*480;
	const DEFAULT_ANALYSIS_IMAGE_AREA = -1; // It is dynamically configured according to hardware
	const MAXIMUM_ANALYSIS_IMAGE_AREA = 3840*2160;

	/** Sensitivity used to clustering */
	const SENSITIVITY_KEY = 'sensitivity';
	const MINIMUM_SENSITIVITY = 0.2;
	const DEFAULT_SENSITIVITY = 0.4;
	const MAXIMUM_SENSITIVITY = 0.6;

	/** Minimum confidence used to try to clustring faces */
	const MINIMUM_CONFIDENCE_KEY = 'min_confidence';
	const MINIMUM_MINIMUM_CONFIDENCE = 0.0;
	const DEFAULT_MINIMUM_CONFIDENCE = 0.99;
	const MAXIMUM_MINIMUM_CONFIDENCE = 1.1;

	/** Minimum face size used to try to clustring faces */
	const MINIMUM_FACE_SIZE_KEY = 'min_face_size';
	const MINIMUM_MINIMUM_FACE_SIZE = 0;
	const DEFAULT_MINIMUM_FACE_SIZE = 40;
	const MAXIMUM_MINIMUM_FACE_SIZE = 250;

	/** Minimum of faces in cluster */
	const MINIMUM_FACES_IN_CLUSTER_KEY = 'min_faces_in_cluster';
	const MINIMUM_MINIMUM_FACES_IN_CLUSTER = 1;
	const DEFAULT_MINIMUM_FACES_IN_CLUSTER = 5;
	const MAXIMUM_MINIMUM_FACES_IN_CLUSTER = 20;

	/** User setting what indicates if has the analysis enabled */
	const USER_ENABLED_KEY = 'enabled';
	// The default is defined by system 'default_enabled' key

	const CLUSTERING_BATCH_SIZE_KEY = 'clustering_batch_size';
	const DEFAULT_CLUSTERING_BATCH_SIZE = -1;

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
	const DEFAULT_HANDLE_SHARED_FILES = false;

	/** Hidden setting that allows to analyze external files */
	const HANDLE_EXTERNAL_FILES_KEY = 'handle_external_files';
	const DEFAULT_HANDLE_EXTERNAL_FILES = false;

	/** Hidden setting that allows to analyze group files */
	const HANDLE_GROUP_FILES_KEY = 'handle_group_files';
	const DEFAULT_HANDLE_GROUP_FILES = false;

	/** Hidden setting that indicate minimum large of image to analyze */
	const MINIMUM_IMAGE_SIZE_KEY = 'min_image_size';
	const DEFAULT_MINIMUM_IMAGE_SIZE = 512;

	/** Hidden setting that indicate maximum area of image to analyze */
	const MAXIMUM_IMAGE_AREA_KEY = 'max_image_area';
	const DEFAULT_MAXIMUM_IMAGE_AREA = -1;

	/** Hidden setting that allows obfuscate that faces for security */
	const OBFUSCATE_FACE_THUMBS_KEY = 'obfuscate_faces';
	const DEFAULT_OBFUSCATE_FACE_THUMBS = false;

	/** System setting to enable mimetypes */

	const SYSTEM_ENABLED_MIMETYPES = 'enabledFaceRecognitionMimetype';
	private $allowedMimetypes = ['image/jpeg', 'image/png'];
	private $cachedAllowedMimetypes = false;

	/** System setting to use custom folder for models */
	const SYSTEM_MODEL_PATH = 'facerecognition.model_path';

	/** System setting to configure external model */
	const SYSTEM_EXTERNAL_MODEL_URL = 'facerecognition.external_model_url';
	const SYSTEM_EXTERNAL_MODEL_API_KEY = 'facerecognition.external_model_api_key';

	/**
	 * SettingsService
	 */

	/** @var IConfig Config */
	private $config;

	/** @var IAppConfig Config */
	private $appConfig;
	
	/**  @var string|null */
	private $userId;

	/**
	 * @param IConfig $config
	 * @param string $userId
	 */
	public function __construct(IConfig $config,
								IAppConfig $appConfig,
	                            $userId)
	{
		$this->config = $config;
		$this->appConfig = $appConfig;
		$this->userId = $userId;
	}

	/*
	 * User settings.
	 */
	/**
	 * @param null|string $userId
	 */
	public function getUserEnabled (?string $userId = null): bool {
		$enabled = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_ENABLED_KEY, $this->getDefaultUserEnabled());
		return ($enabled == 'true');
	}

	public function setUserEnabled (bool $enabled, ?string $userId = null): void {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_ENABLED_KEY, $enabled ? "true" : "false");
	}

	/**
	 * @param null|string $userId
	 */
	public function getUserFullScanDone (?string $userId = null): bool {
		$fullScanDone = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::FULL_IMAGE_SCAN_DONE_KEY, self::DEFAULT_FULL_IMAGE_SCAN_DONE);
		return ($fullScanDone === 'true');
	}

	/**
	 * @param null|string $userId
	 */
	public function setUserFullScanDone (bool $fullScanDone, ?string $userId = null): void {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::FULL_IMAGE_SCAN_DONE_KEY, $fullScanDone ? "true" : "false");
	}

	public function getNeedRemoveStaleImages (?string $userId = null): bool {
		$needRemoval = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_REMOVAL_NEEDED_KEY, self::DEFAULT_STALE_IMAGES_REMOVAL_NEEDED);
		return ($needRemoval === 'true');
	}

	/**
	 * @param null|string $userId
	 */
	public function setNeedRemoveStaleImages (bool $needRemoval, ?string $userId = null): void {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_REMOVAL_NEEDED_KEY, $needRemoval ? "true" : "false");
	}

	/**
	 * @param null|string $userId
	 */
	public function getLastStaleImageChecked (?string $userId = null): int {
		return intval($this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_LAST_CHECKED_KEY, self::DEFAULT_STALE_IMAGES_LAST_CHECKED));
	}

	/**
	 * @param null|string $userId
	 */
	public function setLastStaleImageChecked (int $lastCheck, ?string $userId = null): void {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::STALE_IMAGES_LAST_CHECKED_KEY, strval($lastCheck));
	}

	/**
	 * @param null|string $userId
	 */
	public function getNeedRecreateClusters (?string $userId = null): bool {
		$needRecreate = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_RECREATE_CLUSTERS_KEY, self::DEFAULT_USER_RECREATE_CLUSTERS);
		return ($needRecreate === 'true');
	}

	/**
	 * @param null|string $userId
	 */
	public function setNeedRecreateClusters (bool $needRecreate, ?string $userId = null): void {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::USER_RECREATE_CLUSTERS_KEY, $needRecreate ? "true" : "false");
	}

	// Private function used only on tests
	/**
	 * @param null|string $userId
	 */
	public function _getForceCreateClusters (?string $userId = null): bool {
		$forceCreate = $this->config->getUserValue($userId ?? $this->userId, Application::APP_NAME, self::FORCE_CREATE_CLUSTERS_KEY, self::DEFAULT_FORCE_CREATE_CLUSTERS);
		return ($forceCreate === 'true');
	}

	// Private function used only on tests
	/**
	 * @param null|string $userId
	 */
	public function _setForceCreateClusters (bool $forceCreate, ?string $userId = null): void {
		$this->config->setUserValue($userId ?? $this->userId, Application::APP_NAME, self::FORCE_CREATE_CLUSTERS_KEY, $forceCreate ? "true" : "false");
	}

	/*
	 * Admin and process settings.
	 */
	public function getCurrentFaceModel(): int {
		return intval($this->appConfig->getValueInt(Application::APP_NAME, self::CURRENT_MODEL_KEY, self::FALLBACK_CURRENT_MODEL));
	}

	public function setCurrentFaceModel(int $model): void {
		$this->appConfig->setValueInt(Application::APP_NAME, self::CURRENT_MODEL_KEY, $model);
	}

	public function getAnalysisImageArea(): int {
		return intval($this->appConfig->getValueInt(Application::APP_NAME, self::ANALYSIS_IMAGE_AREA_KEY, self::DEFAULT_ANALYSIS_IMAGE_AREA));
	}

	public function setAssignedMemory(int $assignedMemory): void {
		$this->appConfig->setValueInt(Application::APP_NAME, self::ASSIGNED_MEMORY_KEY, $assignedMemory);
	}

	public function setAnalysisImageArea(int $imageArea): void {
		$this->appConfig->setValueInt(Application::APP_NAME, self::ANALYSIS_IMAGE_AREA_KEY, $imageArea);
	}

	public function getSensitivity(): float {
		return floatval($this->appConfig->getValueFloat(Application::APP_NAME, self::SENSITIVITY_KEY, self::DEFAULT_SENSITIVITY));
	}

	public function setSensitivity(float $sensitivity): void {
		$this->appConfig->setValueFloat(Application::APP_NAME, self::SENSITIVITY_KEY, $sensitivity);
	}

	public function getMinimumConfidence(): float {
		return floatval($this->appConfig->getValueFloat(Application::APP_NAME, self::MINIMUM_CONFIDENCE_KEY, self::DEFAULT_MINIMUM_CONFIDENCE));
	}

	public function setMinimumConfidence(float $confidence): void {
		$this->appConfig->setValueFloat(Application::APP_NAME, self::MINIMUM_CONFIDENCE_KEY, $confidence);
	}

	public function getMinimumFacesInCluster(): int {
		return intval($this->appConfig->getValueInt(Application::APP_NAME, self::MINIMUM_FACES_IN_CLUSTER_KEY, self::DEFAULT_MINIMUM_FACES_IN_CLUSTER));
	}

	public function setMinimumFacesInCluster(int $no_faces): void {
		$this->appConfig->setValueInt(Application::APP_NAME, self::MINIMUM_FACES_IN_CLUSTER_KEY, $no_faces);
	}

	/**
	 * The next settings are advanced preferences that are not available in gui.
	 * See: https://github.com/matiasdelellis/facerecognition/wiki/Settings#hidden-settings
	 */
	public function getDefaultUserEnabled (): bool {
		return $this->appConfig->getValueBool(Application::APP_NAME, self::DEFAULT_USER_ENABLED_KEY, self::DEFAULT_USER_ENABLED);
	}

	public function getClusterigBatchSize(): int {
		if ($this->config->getSystemValue('dbtype', 'sqlite') === 'oci')
			return 1000;
		return $this->appConfig->getValueInt(Application::APP_NAME, self::CLUSTERING_BATCH_SIZE_KEY, self::DEFAULT_CLUSTERING_BATCH_SIZE);
	}

	public function getHandleSharedFiles(): bool {
		return $this->appConfig->getValueBool(Application::APP_NAME, self::HANDLE_SHARED_FILES_KEY, self::DEFAULT_HANDLE_SHARED_FILES);
	}

	public function getHandleExternalFiles(): bool {
		return $this->appConfig->getValueBool(Application::APP_NAME, self::HANDLE_EXTERNAL_FILES_KEY, self::DEFAULT_HANDLE_EXTERNAL_FILES);
	}

	public function getHandleGroupFiles(): bool {
		return $this->appConfig->getValueBool(Application::APP_NAME, self::HANDLE_GROUP_FILES_KEY, self::DEFAULT_HANDLE_GROUP_FILES);
	}

	public function getMinimumImageSize(): int {
		return intval($this->appConfig->getValueInt(Application::APP_NAME, self::MINIMUM_IMAGE_SIZE_KEY, self::DEFAULT_MINIMUM_IMAGE_SIZE));
	}

	public function getMinimumFaceSize(): int {
		$minFaceSize = intval($this->appConfig->getValueInt(Application::APP_NAME, self::MINIMUM_FACE_SIZE_KEY, self::DEFAULT_MINIMUM_FACE_SIZE));
		$minFaceSize = max(self::MINIMUM_MINIMUM_FACE_SIZE, $minFaceSize);
		$minFaceSize = min($minFaceSize, self::MAXIMUM_MINIMUM_FACE_SIZE);
		return $minFaceSize;
	}

	public function getMaximumImageArea(): int {
		return intval($this->appConfig->getValueInt(Application::APP_NAME, self::MAXIMUM_IMAGE_AREA_KEY, self::DEFAULT_MAXIMUM_IMAGE_AREA));
	}

	public function getAssignedMemory(): int {
		return intval($this->appConfig->getValueInt(Application::APP_NAME, self::ASSIGNED_MEMORY_KEY, self::DEFAULT_ASSIGNED_MEMORY));
	}

	public function getObfuscateFaces(): bool {
		return $this->appConfig->getValueBool(Application::APP_NAME, self::OBFUSCATE_FACE_THUMBS_KEY, self::DEFAULT_OBFUSCATE_FACE_THUMBS);
	}

	public function setObfuscateFaces(bool $obfuscate): void {
		$this->appConfig->setValueBool(Application::APP_NAME, self::OBFUSCATE_FACE_THUMBS_KEY, $obfuscate);
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

	/**
	 * System settings that allow use an custom folder to install the models.
	 */
	public function getSystemModelPath(): ?string {
		return $this->config->getSystemValue(self::SYSTEM_MODEL_PATH, null);
	}

	/**
	 * External model url
	 */
	public function getExternalModelUrl(): ?string {
		return $this->config->getSystemValue(self::SYSTEM_EXTERNAL_MODEL_URL, null);
	}

	/**
	 * External model Api Key
	 */
	public function getExternalModelApiKey(): ?string {
		return $this->config->getSystemValue(self::SYSTEM_EXTERNAL_MODEL_API_KEY, null);
	}

}
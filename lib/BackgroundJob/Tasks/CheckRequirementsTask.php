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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCP\IConfig;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Helper\MemoryLimits;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

/**
 * Check all requirements before we start engaging in lengthy background task.
 */
class CheckRequirementsTask extends FaceRecognitionBackgroundTask {
	/** @var IConfig Config */
	private $config;

	/**
	 * @param IConfig $config Config
	 */
	public function __construct(IConfig $config) {
		parent::__construct();
		$this->config = $config;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Check all requirements";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$req = new Requirements($context->appManager, $model);

		if (!$req->pdlibLoaded()) {
			$error_message = "PDLib is not loaded. Cannot continue";
			$this->logInfo($error_message);
			return false;
		}

		if (!$req->modelFilesPresent()) {
			$error_message =
				"Files of model with ID ' . $model . ' are not present in models/ directory.\n" .
				"Please contact administrator to change models you are using for face recognition\n" .
				"or reinstall application. File an issue here if that doesn\'t help: https://github.com/matiasdelellis/facerecognition/issues";
			$this->logInfo($error_message);
			return false;
		}

		// Determine the system memory with some considerations.
		$memory = MemoryLimits::getAvailableMemory();
		if ($memory <= 0) {
			// We cannot determine amount of memory to give to face recognition CNN.
			// We will hardcode it here to 1GB, but plan is to expose this to user in future.
			// TODO: allow user to choose "recognition quality", which will map to given memory.
			// If user explicitely set something, we ignore getting memory from system.
			$this->logDebug("Cannot detect amount of memory given to PHP process. Using 1GB for image processing");
			$memory = 1024 * 1024 * 1024;

		// Apply some prudent limits.
		if ($memory < 1024 * 1024 * 1024) {
			$error_message =
				"\n" .
				"Seems that you have only " . intval($memory / (1024 * 1024)). "MB of memory given to PHP.\n" .
				"Face recognition application requires at least 1 GB. You need to change your memory_limit in php.ini.\n" .
				"Check https://secure.php.net/manual/en/ini.core.php#ini.memory-limit for details.\n" .
				"If you already set this to unlimited, it seems your system is not having enough RAM memory.";
			$this->logInfo($error_message);
			return false;
		} else {
			$this->logDebug(sprintf('Found %d MB available to PHP.', $memory / (1024 * 1024)));
			// No point going above 4GB ("4GB should be enough for everyone")
			if ($memory > 4 * 1024 * 1024 * 1024) {
				$this->logDebug('Capping used memory to maximum of 4GB');
				$memory = 4 * 1024 * 1024 * 1024;
			}
		}

		// Apply admin setting limit.
		$memorySetting = $this->config->getAppValue('facerecognition', 'memory-limits', '-1');
		if ($memorySetting > 0) {
			if ($memorySetting > $memory) {
				$error_message =
					"\n" .
					"Seems that you configured" . intval($memorySetting / (1024 * 1024)) . " MB of memory, however, this exede"
					"detected as maximum for PHP: " . intval($memory / (1024 * 1024)). "MB.\n" .
					"You need to change your memory_limit in php.ini or 'Memory Limits' in settings.\n" .
					"Check https://secure.php.net/manual/en/ini.core.php#ini.memory-limit for details.\n" .
					"If you already set this to unlimited, it seems your system is not having enough RAM memory.";
				$this->logInfo($error_message);
				return false;
			}
			else {
				$this->logInfo("Applying the memory limit to " . intval($memorySetting / (1024*1024)) . "MB configured in settings.");
				$memory = $memorySetting;
			}
		}

		$context->propertyBag['memory'] = $memory;

		return true;
	}
}
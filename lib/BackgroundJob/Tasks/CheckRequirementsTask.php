<?php
/**
 * @copyright Copyright (c) 2017-2020 Matias De lellis <mati86dl@gmail.com>
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

use OCP\Util as OCP_Util;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Helper\MemoryLimits;
use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\Service\SettingsService;

/**
 * Check all requirements before we start engaging in lengthy background task.
 */
class CheckRequirementsTask extends FaceRecognitionBackgroundTask {
	/** @var SettingsService Settings service */
	private $settingsService;

	/**
	 * @param SettingsService $settingsService Settings service
	 */
	public function __construct(SettingsService $settingsService)
	{
		parent::__construct();

		$this->settingsService = $settingsService;
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

		$model = $this->settingsService->getCurrentFaceModel();

		$req = new Requirements($context->modelService, $model);

		if (!$req->pdlibLoaded()) {
			$error_message = "PDLib is not loaded. Cannot continue";
			$this->logInfo($error_message);
			return false;
		}

		if (!$req->modelFilesPresent()) {
			$error_message =
				"Files of model with ID " . $model . " are not present in models/ directory.\n" .
				"Please contact administrator to change models you are using for face recognition\n" .
				"or reinstall them with the 'occ face:setup' command. \n" .
				"Fill an issue here if that doesn't help: https://github.com/matiasdelellis/facerecognition/issues";
			$this->logInfo($error_message);
			return false;
		}

		if (!$req->hasEnoughMemory()) {
			$error_message =
				"Your system does not meet the minimum of memory requirements.\n" .
				"Face recognition application requires at least " . OCP_Util::humanFileSize(SettingsService::MINIMUM_SYSTEM_MEMORY_REQUIREMENTS) . " of system memory.\n";
				"See https://github.com/matiasdelellis/facerecognition/wiki/Performance-analysis-of-DLib%E2%80%99s-CNN-face-detection for more details";
			$this->logInfo($error_message);
			return false;
		}

		// Determine the system memory with some considerations.
		$memoryAvailable = MemoryLimits::getAvailableMemory();
		if ($memoryAvailable <= 0) {
			// We cannot determine amount of memory to give to face recognition CNN.
			// We will hardcode it here to the minimum memory settings.
			$this->logDebug("Cannot detect amount of memory given to PHP process. Using " . OCP_Util::humanFileSize(SettingsService::MINIMUM_MEMORY_LIMITS). " for image processing");
			$memoryAvailable = SettingsService::MINIMUM_MEMORY_LIMITS;
		}

		// Apply some prudent limits.
		if ($memoryAvailable < SettingsService::MINIMUM_MEMORY_LIMITS) {
			$error_message =
				"\n" .
				"Seems that you have only " . OCP_Util::humanFileSize($memoryAvailable). " of memory given to PHP.\n" .
				"Face recognition application requires at least " . OCP_Util::humanFileSize(SettingsService::MINIMUM_MEMORY_LIMITS) .  ". You need to change your memory_limit in php.ini.\n" .
				"Check https://secure.php.net/manual/en/ini.core.php#ini.memory-limit for details.\n" .
				"If you already set this to unlimited, it seems your system is not having enough RAM memory.";
			$this->logInfo($error_message);
			return false;
		}

		$this->logDebug(sprintf('Found %s available to PHP.', OCP_Util::humanFileSize($memoryAvailable)));

		// No point going above 4GB ("4GB should be enough for everyone")
		if ($memoryAvailable > SettingsService::MAXIMUM_MEMORY_LIMITS) {
			$this->logDebug('Capping used memory to maximum of ' . OCP_Util::humanFileSize(SettingsService::MAXIMUM_MEMORY_LIMITS));
			$memoryAvailable = SettingsService::MAXIMUM_MEMORY_LIMITS;
		}

		// Apply admin setting limit.
		$memorySetting = $this->settingsService->getMemoryLimits();
		if ($memorySetting > 0) {
			if ($memorySetting > $memoryAvailable) {
				$error_message =
					"\n" .
					"Seems that you configured " . OCP_Util::humanFileSize($memorySetting) . " of memory on FaceRecognition settings,\n" .
					"however, this exceeds detected as maximum for PHP: " . OCP_Util::humanFileSize($memoryAvailable) . ".\n" .
					"We will ignore the FaceRecognition settings, and we will limit to that.";
				$this->logInfo($error_message);
			}
			else {
				$this->logInfo("Applying the memory limit to " . OCP_Util::humanFileSize($memorySetting) . " configured in settings.");
				$memoryAvailable = $memorySetting;
			}
		}

		$context->propertyBag['memory'] = $memoryAvailable;

		return true;
	}
}
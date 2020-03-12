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

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\SettingsService;

/**
 * Check all requirements before we start engaging in lengthy background task.
 */
class CheckRequirementsTask extends FaceRecognitionBackgroundTask {

	/** @var ModelManager Model Manader */
	private $modelManager;

	/** @var SettingsService Settings service */
	private $settingsService;

	/**
	 * @param ModelManager $modelManager Model Manager
	 * @param SettingsService $settingsService Settings service
	 */
	public function __construct(ModelManager    $modelManager,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->modelManager    = $modelManager;
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

		if (!Requirements::pdlibLoaded()) {
			$error_message = "The PDlib PHP extension is not loaded. Cannot continue without it.";
			$this->logInfo($error_message);
			return false;
		}

		if (!Requirements::hasEnoughMemory()) {
			$error_message =
				"Your system does not meet the minimum of memory requirements.\n" .
				"Face recognition application requires at least " . OCP_Util::humanFileSize(SettingsService::MINIMUM_SYSTEM_MEMORY_REQUIREMENTS) . " of system memory.\n";
				"See https://github.com/matiasdelellis/facerecognition/wiki/Performance-analysis-of-DLib%E2%80%99s-CNN-face-detection for more details";
			$this->logInfo($error_message);
			return false;
		}

		$model = $this->modelManager->getCurrentModel();
		if (is_null($model)) {
			$error_message =
				"Seems there are no installed models.\n" .
				"Please contact administrator to change models you are using for face recognition\n" .
				"or reinstall them with the 'occ face:setup --model' command. \n\n" .
				"Fill an issue here if that doesn't help: https://github.com/matiasdelellis/facerecognition/issues";
			$this->logInfo($error_message);
			return false;
		}

		if (!$model->meetDependencies()) {
			$error_message = "Seems that don't meet the dependencies to use the model " . $model->getId() .": " . $model->getName();
			// Document models on wiki and print link here.
			$this->logInfo($error_message);
			return false;
		}

		$imageArea = $this->settingsService->getAnalysisImageArea();
		if ($imageArea < 0) {
			$error_message =
				"Seems that still don't configured the image area used for analysis.\n" .
				"Please contact administrator to configure it in the admin panel to continue.\n" .
				"Fill an issue here if that doesn't help: https://github.com/matiasdelellis/facerecognition/issues";
			$this->logInfo($error_message);
			return false;
		}

		$maxImageArea = $model->getMaximumArea();
		if ($imageArea > $maxImageArea) {
			$error_message =
				"There are inconsistencies between the configured image area (" . $imageArea. " pixels^2) which is\n" .
				"greater that the maximum allowed by the model (". $maxImageArea . " pixels^2).\n" .
				"Please contact administrator to configure it in the admin panel to continue.\n" .
				"Fill an issue here if that doesn't help: https://github.com/matiasdelellis/facerecognition/issues";
			$this->logInfo($error_message);
			return false;
		}

		$systemMemory = MemoryLimits::getSystemMemory();
		$this->logDebug("System memory: " . ($systemMemory > 0 ? $systemMemory : "Unknown"));

		$phpMemory = MemoryLimits::getPhpMemory();
		$this->logDebug("PHP Memory Limit: " . ($phpMemory > 0 ? $phpMemory : "Unknown"));

		return true;
	}
}
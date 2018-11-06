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
	public function do(FaceRecognitionContext $context) {
		$this->setContext($context);
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$req = new Requirements($context->appManager, $model);

		if (!$req->pdlibLoaded()) {
			$this->logInfo('PDLib is not loaded. Cannot continue');
			// todo: convert to exception
			return;
		}

		if (!$req->modelFilesPresent()) {
			$this->logInfo('Files of model with ID ' . $model . ' are not present in models/ directory.');
			$this->logInfo('Please contact administrator to change models you are using for face recognition');
			$this->logInfo('or reinstall application. File an issue here if that doesn\'t help: https://github.com/matiasdelellis/facerecognition/issues');
			// todo: convert to exception
			return;
		}
	}
}
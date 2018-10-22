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

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

/**
 * Check that we are started either through command, or from cron/webcron (but do not allow ajax mode)
 */
class CheckCronTask extends FaceRecognitionBackgroundTask {
	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Check that service is started from either cron or from command";
	}

	/**
	 * @inheritdoc
	 */
	public function do(FaceRecognitionContext $context) {
		$this->setContext($context);

		$isCommand = $context->isRunningThroughCommand();
		$isBackgroundJobModeAjax = $context->config->getAppValue('core', 'backgroundjobs_mode', 'ajax') === 'ajax';
		if ($isCommand === false && $isBackgroundJobModeAjax === false) {
			$message =
				"Face recognition background service can only run with cron/webcron.\n" .
				"For details, take a look at " .
				"https://docs.nextcloud.com/server/14/admin_manual/configuration_server/background_jobs_configuration.html";
			$this->logInfo($message);
			throw new \RuntimeException($message);
		}
	}
}
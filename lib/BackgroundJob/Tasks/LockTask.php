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
 * Tasks that do flock over file and acts as a global mutex,
 * so we don't run more than one background task in parallel.
 */
class LockTask extends FaceRecognitionBackgroundTask {
	const LOCK_FILENAME = 'nextcloud_face_recognition_lock.pid';

	public function description() {
		return "Acquire lock so that only one background task can run";
	}

	public function do(FaceRecognitionContext $context) {
		$lock_file = sys_get_temp_dir() . '/' . LOCK_FILENAME;
		$fp = fopen($lock_file, 'w');

		if (!$fp || !flock($fp, LOCK_EX | LOCK_NB, $eWouldBlock) || $eWouldBlock) {
			$this->logInfo('Seems that background job is already running. Quitting');
			// todo: convert to exception
			return;
		}

		$context->propertyBag['lock'] = $fp;
		$context->propertyBag['lock_filename'] = $lock_file;
	}
}
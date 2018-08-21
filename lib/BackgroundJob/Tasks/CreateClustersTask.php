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

use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

/**
 * Taks that, for each user, creates person clusters for each.
 */
class CreateClustersTask extends FaceRecognitionBackgroundTask {
	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Create new persons or update existing persons";
	}

	/**
	 * @inheritdoc
	 */
	public function do(FaceRecognitionContext $context) {
		$this->setContext($context);

		if (is_null($this->context->user)) {
			$this->context->userManager->callForSeenUsers(function (IUser $user) {
				$this->createClusterIfNeeded($user);
			});
		} else {
			$this->createClusterIfNeeded($this->context->user);
		}
	}

	private function createClusterIfNeeded(IUser $user) {
		// todo
		// start chinese whispers clustering on what we got up to now
		// Once clusters are found, insert them into DB, attach generic names to them (“person-<N>”).
		// Find N by getting all „person-<N>“ for a given user, finding maximum and using N+1 for next one.
	}
}
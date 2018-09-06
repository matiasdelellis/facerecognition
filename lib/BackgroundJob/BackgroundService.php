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
namespace OCA\FaceRecognition\BackgroundJob;

use OCA\FaceRecognition\AppInfo\Application;
use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\BackgroundJob\Tasks\CheckPdlibTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\LockTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\UnlockTask;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Background service. Both command and cron job are calling this service for long-running background operations.
 * Background processing for face recognition is comprised of several steps, called tasks. Each task is independent,
 * idempotent, DI-aware logic unit that yields. Since tasks are non-preemptive, they should yield from time to time, so we son't end up
 * working for more than given timeout.
 *
 * Tasks can be seen as normal sequential functions, but they are easier to work with,
 * reason about them and test them independently. Other than that, they are really glorified functions.
 */
class BackgroundService {

	/** @var Application $application */
	private $application;

	/** @var FaceRecognitionContext */
	private $context;

	public function __construct(Application $application, FaceRecognitionContext $context) {
		$this->application = $application;
		$this->context = $context;
	}

	public function setLogger($logger) {
		// todo: relax this check, so that logger could be set, anytime, but before execute is called
		if (!is_null($this->context->logger)) {
			throw new \LogicException('You cannot call setLogger after you set it once');
		}

		$this->context->logger = new FaceRecognitionLogger($logger);
	}

	/**
	 * Starts background tasks sequentially.
	 * @param int $timeout Maximum allowed time (in seconds) to execute
	 * @param bool $verbose Whether to be more verbose
	 * @param IUser|null $user ID of user to execute background operations for
	 *
	 */
	public function execute(int $timeout, bool $verbose, IUser $user = null) {
		// Put to context all the stuff we are figuring only now
		//
		$this->context->user = $user;
		$this->context->verbose = $verbose;

		// Here we are defining all the tasks that will get executed.
		//
		$task_classes = [
			CheckPdlibTask::class,
			// todo: check if we are started from cron job. If we are and cron job happens to be AJAX, bail out.
			LockTask::class,
			CreateClustersTask::class,
			AddMissingImagesTask::class,
			EnumerateImagesMissingFacesTask::class,
			ImageProcessingTask::class,
			// ...
			UnlockTask::class
		];

		// todo: implement bailing with exceptions
		// Main logic to iterate over all tasks and executes them.
		//
		$startTime = time();
		for ($i=0; $i < count($task_classes); $i++) {
			$task_class = $task_classes[$i];
			$task = $this->application->getContainer()->query($task_class);
			$this->context->logger->logInfo(sprintf("%d/%d - Executing task %s (%s)",
				$i+1, count($task_classes), (new \ReflectionClass($task_class))->getShortName(), $task->description()));

			$generator = $task->do($this->context);
			foreach ($generator as $_) {
				$currentTime = time();
				if (($timeout > 0) && ($currentTime - $startTime > $timeout)) {
					$this->context->logger->logInfo("Time out. Quitting...");
					return;
				}

				$this->context->logger->logDebug('yielding');
			}
		}
	}
}
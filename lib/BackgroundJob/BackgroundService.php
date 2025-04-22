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

use OCP\IUser;

use OCA\FaceRecognition\AppInfo\Application;
use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\CheckCronTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\CheckRequirementsTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\DisabledUserRemovalTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingWithMultipleExternalModelInstancesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\StaleImagesRemovalTask;

use OCA\FaceRecognition\Model\ExternalModel\ExternalModel;
use OCA\FaceRecognition\Service\SettingsService;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Background service. Both command and cron job are calling this service for long-running background operations.
 * Background processing for face recognition is comprised of several steps, called tasks. Each task is independent,
 * idempotent, DI-aware logic unit that yields. Since tasks are non-preemptive, they should yield from time to time,
 * so we son't end up working for more than given timeout.
 *
 * Tasks can be seen as normal sequential functions, but they are easier to work with,
 * reason about them and test them independently. Other than that, they are really glorified functions.
 */
class BackgroundService {

	/** @var Application $application */
	private $application;

	/** @var FaceRecognitionContext $context */
	private $context;

	/** @var SettingsService */
	protected $settingsService;	

	public function __construct(Application $application, 
								SettingsService  $settingsService,
								FaceRecognitionContext $context) {
		$this->application = $application;
		$this->context = $context;

		$this->settingsService    = $settingsService;
	}

	public function setLogger(OutputInterface $logger): void {
		if (!is_null($this->context->logger)) {
			// If you get this exception, it means you already initialized context->logger. Double-check your flow.
			throw new \LogicException('You cannot call setLogger after you set it once');
		}

		$this->context->logger = new FaceRecognitionLogger($logger);
	}

	private function getImageProcessingTask(): string {
		if($this->settingsService->getCurrentFaceModel() == ExternalModel::FACE_MODEL_ID and $this->settingsService->getExternalModelNumberOfInstances() > 1) {
			return ImageProcessingWithMultipleExternalModelInstancesTask::class;
		}
		return ImageProcessingTask::class; 
	}

	/**
	 * Starts background tasks sequentially.
	 *
	 * @param int $timeout Maximum allowed time (in seconds) to execute
	 * @param bool $verbose Whether to be more verbose
	 * @param IUser|null $user ID of user to execute background operations for
	 * @param int|null $maxImageArea Max image area (in pixels^2) to be fed to neural network when doing face detection
	 * @param string $runMode The command execution mode
	 *
	 * @return void
	 */
	public function execute(int $timeout, bool $verbose, IUser $user = null, int $maxImageArea = null, string $runMode) {
		// Put to context all the stuff we are figuring only now
		//
		$this->context->user = $user;
		$this->context->verbose = $verbose;
		$this->context->setRunningThroughCommand();
		$this->context->propertyBag['max_image_area'] = $maxImageArea;
		$this->context->propertyBag['run_mode'] = $runMode;

		// Here we are defining all the tasks that will get executed.
		//
		$task_classes = [
			CheckRequirementsTask::class,
			CheckCronTask::class,
		];

		switch ($runMode)
		{
			case 'sync-mode':
				$task_classes[] = DisabledUserRemovalTask::class;
				$task_classes[] = StaleImagesRemovalTask::class;
				$task_classes[] = AddMissingImagesTask::class;
				break;
			case 'analyze-mode':
				$task_classes[] = EnumerateImagesMissingFacesTask::class;
				$task_classes[] = $this->getImageProcessingTask();
				break;
			case 'cluster-mode':
				$task_classes[] = CreateClustersTask::class;
				break;
			case 'defer-mode':
				$task_classes[] = DisabledUserRemovalTask::class;
				$task_classes[] = StaleImagesRemovalTask::class;
				$task_classes[] = AddMissingImagesTask::class;
				$task_classes[] = EnumerateImagesMissingFacesTask::class;
				$task_classes[] = $this->getImageProcessingTask();
				$task_classes[] = CreateClustersTask::class;
				break;
			case 'default-mode':
			default:
				$task_classes[] = DisabledUserRemovalTask::class;
				$task_classes[] = StaleImagesRemovalTask::class;
				$task_classes[] = CreateClustersTask::class;
				$task_classes[] = AddMissingImagesTask::class;
				$task_classes[] = EnumerateImagesMissingFacesTask::class;
				$task_classes[] = $this->getImageProcessingTask();
				break;
		}

		// Main logic to iterate over all tasks and executes them.
		//
		$startTime = time();
		for ($i=0, $task_classes_count = count($task_classes); $i < $task_classes_count; $i++) {
			$task_class = $task_classes[$i];
			$task = $this->application->getContainer()->query($task_class);
			$this->context->logger->logInfo(sprintf("%d/%d - Executing task %s (%s)",
				$i+1, count($task_classes), (new \ReflectionClass($task_class))->getShortName(), $task->description()));

			try {
				$generator = $task->execute($this->context);
				// $generator can be either actual Generator or return value of boolean.
				// If it is Generator object, that means execute() had some yields.
				// Iterate through those yields and we will get end result through getReturn().
				if ($generator instanceof \Generator) {
					foreach ($generator as $_) {
						$currentTime = time();
						if (($timeout > 0) && ($currentTime - $startTime > $timeout)) {
							$this->context->logger->logInfo("Time out. Quitting...");
							$task->cleanUpOnTimeout();
							return;
						}

						if ($this->context->verbose) {
							$this->context->logger->logDebug('yielding');
						}
					}
				}

				$shouldContinue = ($generator instanceof \Generator) ? $generator->getReturn() : $generator;
				if (!$shouldContinue) {
					$this->context->logger->logInfo(
						sprintf("Task %s signalled we should not continue, bailing out",
						(new \ReflectionClass($task_class))->getShortName()));
					return;
				}
			} catch (\Exception $e) {
				// Any exception is fatal, and we should quit background job
				//
				$this->context->logger->logInfo("Error during background task execution");
				$this->context->logger->logInfo("If error is not transient, this means that core component of face recognition is not working properly");
				$this->context->logger->logInfo("and that quantity and quality of detected faces and person will be low or suboptimal.");
				$this->context->logger->logInfo("You probably want to file an issue (please include exception below) to: https://github.com/matiasdelellis/facerecognition/issues");
				throw $e;
			}
		}
	}
}

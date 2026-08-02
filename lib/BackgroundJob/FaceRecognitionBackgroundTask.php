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

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

/**
 * Interface that each face recognition background task should implement
 */
interface IFaceRecognitionBackgroundTask {
	/**
	 * Returns task's description.
	 *
	 * @return string Description of what task do
	 */
	public function description();

	/**
	 * Executes task.
	 *
	 * @param FaceRecognitionContext $context Face recognition context
	 * @return \Generator|bool Since we are yielding, return type is either Generator, or boolean (actual return).
	 * Return value specifies should we continue execution. True if we should continue, false if we should bail out.
	 */
	public function execute(FaceRecognitionContext $context);
}

/**
 * Abstract implementation for background task, serves as a helper for common functions.
 */
abstract class FaceRecognitionBackgroundTask implements IFaceRecognitionBackgroundTask {

	/** @var FaceRecognitionContext $context */
	protected $context;

	public function __construct() {
	}

	/**
	 * Sets context for a given task, so it can be accessed in task (without a need to dragging it around from execute() method).
	 * Currently public, because of tests (ideally it should be protected).
	 *
	 * @param FaceRecognitionContext $context Context
	 *
	 * @return void
	 */
	public function setContext(FaceRecognitionContext $context): void {
		$this->context = $context;
	}

	/**
	 * Prefix of the worker that is running this task, when it runs in parallel
	 * as a worker, so that the logs of the different processes can be told
	 * apart. Empty when it is a regular, single-threaded run.
	 *
	 * @return string
	 */
	private function getWorkerPrefix(): string {
		$workerIndex = $this->context->propertyBag['worker_index'] ?? null;
		$workerCount = $this->context->propertyBag['worker_count'] ?? null;
		if (is_null($workerIndex) || is_null($workerCount)) {
			return '';
		}
		return sprintf('[W %d/%d] ', $workerIndex, $workerCount);
	}

	/**
	 * Wrapper for info logging. It using this log call, it will indent log messages,
	 * so there is nice visual that those messages belongs to particular task.
	 *
	 * @return void
	 */
	protected function logInfo(string $message): void {
		$this->context->logger->logInfo("\t" . $this->getWorkerPrefix() . $message);
	}

	/**
	 * Wrapper for debug logging. It using this log call, it will indent log messages,
	 * so there is nice visual that those messages belongs to particular task.
	 *
	 * @return void
	 */
	protected function logDebug(string $message): void {
		if ($this->context->verbose) {
			$this->context->logger->logDebug("\t" . $this->getWorkerPrefix() . $message);
		}
	}
}

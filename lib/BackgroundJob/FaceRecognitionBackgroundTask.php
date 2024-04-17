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
	 * Clean up temporary data and files if needed.
	 * This method will be called when the task is termindated prematurely due to timeout.
	 */
	public function cleanUpOnTimeout(): void {
		$classname = explode('\\', get_class($this));
		$this->logDebug(sprintf("The %s has been terminated prematurely. Cleaning up.", end($classname)));
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
	 * Wrapper for info logging. It using this log call, it will indent log messages,
	 * so there is nice visual that those messages belongs to particular task.
	 *
	 * @return void
	 */
	protected function logInfo(string $message): void {
		$this->context->logger->logInfo("\t" . $message);
	}

	/**
	 * Wrapper for debug logging. It using this log call, it will indent log messages,
	 * so there is nice visual that those messages belongs to particular task.
	 *
	 * @return void
	 */
	protected function logDebug(string $message): void {
		if ($this->context->verbose) {
			$this->context->logger->logDebug("\t" . $message);
		}
	}
}
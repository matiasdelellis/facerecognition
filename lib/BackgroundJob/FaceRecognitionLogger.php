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

use Psr\Log\LoggerInterface;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Logger class that encapsulates logging for background tasks. Reason for this
 * class is because background processing can be called as either command, as
 * well as background job, so we need a way to unify logging for both.
 */
class FaceRecognitionLogger {
	/** @var LoggerInterface */
	private $logger;

	/** @var OutputInterface */
	private $output;

	/** @var LoggerInterface|null Nextcloud log for the warnings of a command run */
	private $warningLogger;

	/**
	 * @param LoggerInterface|OutputInterface $logger
	 * @param LoggerInterface|null $warningLogger when $logger is the console of
	 *   a command: the Nextcloud log, which gets the warnings as well. The
	 *   background job only runs as a command, from the system cron, whose
	 *   output is usually thrown away, and a warning must not go with it.
	 */
	public function __construct($logger, ?LoggerInterface $warningLogger = null) {
		if (method_exists($logger, 'info') && method_exists($logger, 'debug')) {
			$this->logger = $logger;
		} else if ($logger instanceof OutputInterface) {
			$this->output = $logger;
			$this->warningLogger = $warningLogger;
		} else {
			throw new \InvalidArgumentException("Logger must be either instance of LoggerInterface or OutputInterface");
		}
	}

	/**
	 * Returns logger, if it is set
	 *
	 * @return LoggerInterface|null Logger, if it is set.
	 */
	public function getLogger() {
		return $this->logger;
	}

	public function logInfo(string $message): void {
		if (!is_null($this->logger)) {
			$this->logger->info($message);
		} else if (!is_null($this->output)) {
			$this->output->writeln($message);
		} else {
			throw new \RuntimeException("There are no configured loggers. Please file an issue at https://github.com/matiasdelellis/facerecognition/issues");
		}
	}

	/**
	 * Something went wrong, but the run goes on. Logged as a warning, so that
	 * it is in the Nextcloud log at the default log level, where the info
	 * lines are not; in a command run both on the console and there.
	 */
	public function logWarning(string $message): void {
		if (!is_null($this->logger)) {
			$this->logger->warning($message);
		} else if (!is_null($this->output)) {
			$this->output->writeln($message);
			if (!is_null($this->warningLogger)) {
				$this->warningLogger->warning(trim($message), ['app' => 'facerecognition']);
			}
		} else {
			throw new \RuntimeException("There are no configured loggers. Please file an issue at https://github.com/matiasdelellis/facerecognition/issues");
		}
	}

	public function logDebug(string $message): void {
		if (!is_null($this->logger)) {
			$this->logger->debug($message);
		} else if (!is_null($this->output)) {
			$this->output->writeln($message);
		} else {
			throw new \RuntimeException("There are no configured loggers. Please file an issue at https://github.com/matiasdelellis/facerecognition/issues");
		}
	}
}
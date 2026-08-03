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
namespace OCA\FaceRecognition\Command;

use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\Helper\CommandLock;

use OCA\FaceRecognition\BackgroundJob\BackgroundService;
use OCA\FaceRecognition\BackgroundJob\WorkerConfig;

class BackgroundCommand extends Command {

	private const ENV_WORKER_CONF = 'FACERECOGNITION_WORKER_CONF';

	/** @var BackgroundService */
	protected $backgroundService;

	/** @var IUserManager */
	protected $userManager;

	/**
	 * @param BackgroundService $backgroundService
	 * @param IUserManager $userManager
	 */
	public function __construct(BackgroundService $backgroundService,
	                            IUserManager      $userManager) {
		parent::__construct();

		$this->backgroundService = $backgroundService;
		$this->userManager = $userManager;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:background_job')
			->setDescription('Equivalent of cron job to analyze images, extract faces and create clusters from found faces')
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Analyze faces for the given user only. If not given, analyzes images for all users.',
				null
			)
			->addOption(
				'max_image_area',
				'M',
				InputOption::VALUE_REQUIRED,
				'Caps maximum area (in pixels^2) of the image to be fed to neural network, effectively lowering needed memory. ' .
				'Use this if face detection crashes randomly.'
			)
			->addOption(
				'sync-mode',
				null,
				InputOption::VALUE_NONE,
				'Execute all actions related to synchronizing the files. New users, shared or deleted files, etc.'
			)
			->addOption(
				'fast-mode',
				null,
				InputOption::VALUE_NONE,
				'First pass of the analysis: process all images with the fast HOG model on a small image, to get groupings and persons quickly. The refinement to maximum quality is done by the default mode.'
			)
			->addOption(
				'defer-clustering',
				null,
				InputOption::VALUE_NONE,
				'Defer the face clustering at the end of the analysis to get persons in a simple execution of the command.'
			)
			->addOption(
				'timeout',
				't',
				InputOption::VALUE_REQUIRED,
				'Sets timeout in seconds for this command. Default is without timeout, e.g. command runs indefinitely.',
				0
			)
			->addOption(
				'workers',
				'w',
				InputOption::VALUE_REQUIRED,
				'Spawn multiple parallel workers to speed up the image analysis. Requires the pcntl extension.'
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->backgroundService->setLogger($output);

		$user = $this->getUser($input);
		$timeout = $this->getTimeout($input);
		$maxImageArea = $this->getMaxImageArea($input);
		$mode = $this->getMode($input);
		$verbose = $input->getOption('verbose');

		// Worker configuration. A worker is a re-executed copy of this command
		// that the coordinator spawns, so it is told which share of the images
		// it has to analyze, and in which mode, through an environment
		// variable. A worker never takes the global lock, which the coordinator
		// is already holding, so the workers do not fight each other.
		//
		$workerConfig = $this->getWorkerConfigFromEnvironment();
		if (!is_null($workerConfig)) {
			$mode = $workerConfig->getMode();
		}

		// If we are the coordinator (we were asked to spawn workers and we are
		// not a worker ourselves), fork the workers and wait for them. The
		// workers re-execute this same command with the worker configuration in
		// the environment, and do the actual analysis.
		//
		$workerCount = $this->getWorkerCount($input);
		if (is_null($workerConfig) && !is_null($workerCount)) {
			return $this->runAsCoordinator($output, $timeout, $verbose, $mode, $user, $maxImageArea, $workerCount);
		}

		// Either a plain run or a worker. Both analyze the images; the worker
		// gets its share of the work from the worker configuration, and the
		// plain run (which is also the coordinator, when there are no workers)
		// additionally runs the file synchronization and the clustering.
		//
		return $this->runAnalysis($output, $timeout, $verbose, $mode, $user, $maxImageArea, $workerConfig);
	}

	/**
	 * Extract the user to analyze, if any.
	 *
	 * @param InputInterface $input
	 *
	 * @return IUser|null The user to analyze, or null for all users.
	 *
	 * @throws \InvalidArgumentException If the given user id does not exist.
	 */
	private function getUser(InputInterface $input): ?IUser {
		$userId = $input->getOption('user_id');
		if (is_null($userId)) {
			return null;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new \InvalidArgumentException("User with id <$userId> in unknown.");
		}

		return $user;
	}

	/**
	 * Extract the timeout.
	 *
	 * @param InputInterface $input
	 *
	 * @return int The timeout in seconds, or 0 for no timeout.
	 *
	 * @throws \InvalidArgumentException If the given timeout is negative.
	 */
	private function getTimeout(InputInterface $input): int {
		$timeout = $input->getOption('timeout');
		if (is_null($timeout)) {
			return 0;
		}

		if ($timeout < 0) {
			throw new \InvalidArgumentException("Timeout must be positive value in seconds.");
		}

		return $timeout;
	}

	/**
	 * Extract the maximum image area.
	 *
	 * @param InputInterface $input
	 *
	 * @return int|null The maximum area in pixels^2, or null if not capped.
	 *
	 * @throws \InvalidArgumentException If the given area is not positive.
	 */
	private function getMaxImageArea(InputInterface $input): ?int {
		$maxImageArea = $input->getOption('max_image_area');
		if (is_null($maxImageArea)) {
			return null;
		}

		$maxImageArea = intval($maxImageArea);
		if ($maxImageArea === 0) {
			throw new \InvalidArgumentException("Max image area must be positive number.");
		}

		if ($maxImageArea < 0) {
			throw new \InvalidArgumentException("Max image area must be positive value.");
		}

		return $maxImageArea;
	}

	/**
	 * Extract the run mode from the command line options.
	 *
	 * @param InputInterface $input
	 *
	 * @return string The mode: `sync-mode`, `fast-mode`, `defer-mode` or `default-mode`.
	 */
	private function getMode(InputInterface $input): string {
		if ($input->getOption('sync-mode')) {
			return 'sync-mode';
		}

		if ($input->getOption('fast-mode')) {
			return 'fast-mode';
		}

		if ($input->getOption('defer-clustering')) {
			return 'defer-mode';
		}

		return 'default-mode';
	}

	/**
	 * Extract the number of workers to spawn.
	 *
	 * @param InputInterface $input
	 *
	 * @return int|null The number of workers, or null if `--workers` was not given.
	 */
	private function getWorkerCount(InputInterface $input): ?int {
		$workers = $input->getOption('workers');
		if (is_null($workers)) {
			return null;
		}

		return intval($workers);
	}

	/**
	 * Run this command as the coordinator: synchronize the files, spawn the
	 * workers and wait for them, and cluster the faces once they are done.
	 *
	 * @param OutputInterface $output
	 * @param int $timeout
	 * @param bool $verbose
	 * @param string $mode
	 * @param IUser|null $user
	 * @param int|null $maxImageArea
	 * @param int $workerCount
	 *
	 * @return int 0 on success, 1 otherwise.
	 */
	private function runAsCoordinator(OutputInterface $output, int $timeout, bool $verbose, string $mode, ?IUser $user, ?int $maxImageArea, int $workerCount): int {
		if ($mode === 'sync-mode') {
			$output->writeln('<error>--workers is only supported when the command analyzes the images, so it cannot be used with --sync-mode.</error>');
			return 1;
		}

		if ($workerCount <= 0) {
			$output->writeln('<error>Invalid worker count: ' . $workerCount . '</error>');
			return 1;
		}

		if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec') || !function_exists('pcntl_waitpid')) {
			$output->writeln('<error>The pcntl extension is not available or is disabled in this PHP build, so workers cannot be spawned. ' .
				'Install pcntl (on FreeBSD: pkg install php<version>-pcntl; on Debian/Ubuntu it is included in php-cli), ' .
				'or run the analysis without --workers.</error>');
			return 1;
		}

		return $this->withGlobalLock($output, function () use ($output, $timeout, $verbose, $mode, $user, $maxImageArea, $workerCount) {
			// The coordinator runs the file synchronization before spawning the
			// workers (so they analyze the images that were just added), and
			// the clustering once they are done. It holds the global lock
			// during the whole run, so only one such command runs at a time.
			//
			$this->backgroundService->execute($timeout, $verbose, $mode, $user, $maxImageArea, null, BackgroundService::STAGE_BEFORE_ANALYSIS);

			$exitCode = $this->coordinate($output, $workerCount, $mode);
			if ($exitCode === 0) {
				$this->backgroundService->execute($timeout, $verbose, $mode, $user, $maxImageArea, null, BackgroundService::STAGE_AFTER_ANALYSIS);
			}

			return $exitCode;
		});
	}

	/**
	 * Run the image analysis, either as a worker or as a plain run.
	 *
	 * @param OutputInterface $output
	 * @param int $timeout
	 * @param bool $verbose
	 * @param string $mode
	 * @param IUser|null $user
	 * @param int|null $maxImageArea
	 * @param WorkerConfig|null $workerConfig
	 *
	 * @return int 0 on success, 1 otherwise.
	 */
	private function runAnalysis(OutputInterface $output, int $timeout, bool $verbose, string $mode, ?IUser $user, ?int $maxImageArea, ?WorkerConfig $workerConfig): int {
		// Acquire the lock so that only one background task can run at a time.
		// A worker never acquires it: the coordinator that spawned it is
		// already holding it.
		//
		$run = function () use ($timeout, $verbose, $mode, $user, $maxImageArea, $workerConfig) {
			$this->backgroundService->execute($timeout, $verbose, $mode, $user, $maxImageArea, $workerConfig);
			return 0;
		};

		if (!is_null($workerConfig)) {
			return $run();
		}

		return $this->withGlobalLock($output, $run);
	}

	/**
	 * Execute the given callback while holding the global lock, so only one
	 * background task runs at a time.
	 *
	 * @param OutputInterface $output
	 * @param callable():int $run The work to do while holding the lock.
	 *
	 * @return int The exit code of the run, or 1 if the lock could not be taken.
	 */
	private function withGlobalLock(OutputInterface $output, callable $run): int {
		$lock = CommandLock::Lock('face:background_job');
		if (!$lock) {
			$output->writeln("Another task ('". CommandLock::IsLockedBy().  "') is already running that prevents it from continuing.");
			return 1;
		}

		try {
			return $run();
		} finally {
			CommandLock::Unlock($lock);
		}
	}

	/**
	 * Reads the worker configuration from the environment, if this process is
	 * a worker spawned by the coordinator.
	 *
	 * @return WorkerConfig|null The worker configuration, or null if this
	 * process is not a worker.
	 *
	 * @throws \InvalidArgumentException If the worker configuration is invalid.
	 */
	private function getWorkerConfigFromEnvironment(): ?WorkerConfig {
		$workerConfigEnv = getenv(self::ENV_WORKER_CONF);
		if (!$workerConfigEnv) {
			return null;
		}

		$data = json_decode($workerConfigEnv, true);
		if (!is_array($data)) {
			throw new \InvalidArgumentException('Invalid worker configuration in ' . self::ENV_WORKER_CONF . ' environment variable.');
		}

		return WorkerConfig::fromJson($data);
	}

	/**
	 * Spawns the given number of workers, each one re-executing this same
	 * command with its share of the work, and waits for them all.
	 *
	 * @param OutputInterface $output Output to write to.
	 * @param int $workerCount Number of workers to spawn.
	 * @param string $mode The mode of the run, which each worker inherits. Of
	 * the whole run a worker only executes the analysis, but the mode still
	 * tells it which pass to run: the fast one of `--fast-mode` or the
	 * refinement of every other mode.
	 *
	 * @return int 0 if all workers succeeded, 1 otherwise.
	 */
	private function coordinate(OutputInterface $output, int $workerCount, string $mode): int {
		$workerPids = [];
		for ($i = 0; $i < $workerCount; $i++) {
			$output->writeln('Spawning worker ' . $i);

			$workerConfig = new WorkerConfig($i, $workerCount, $mode);
			$pid = pcntl_fork();
			if ($pid == -1) {
				$output->writeln('<error>Failed to fork worker</error>');
				// Reap the workers that were already spawned, so they do not
				// stay around as zombies.
				foreach ($workerPids as $workerPid) {
					pcntl_waitpid($workerPid, $status);
				}
				return 1;
			} elseif ($pid) {
				// Parent
				$workerPids[] = $pid;
			} else {
				// Child: re-execute the command as a worker. exec replaces the
				// process image, so it never returns on success: the forked
				// state (database connections, etc.) is discarded and each
				// worker starts fresh. When it returns it is because it failed,
				// so print the error and give up.
				$env = getenv();
				$env[self::ENV_WORKER_CONF] = json_encode($workerConfig);
				pcntl_exec(PHP_BINARY, $_SERVER['argv'], $env);
				$output->writeln('<error>Failed to re-exec worker</error>');
				exit(1);
			}
		}

		$workerFailed = false;
		foreach ($workerPids as $index => $pid) {
			$status = 0;
			pcntl_waitpid($pid, $status);
			if (pcntl_wifexited($status)) {
				// Normal exit: report its exit code.
				$exitCode = pcntl_wexitstatus($status);
				$output->writeln('Worker ' . $index . ' exited with code ' . $exitCode);
			} else {
				// The worker did not exit normally: it was killed by a signal
				// (e.g. the OOM-killer, since each worker loads its own copy
				// of the model) or it was stopped. Either way it did not do
				// its share of the work, so treat it as a failure and do not
				// report a success. pcntl_wexitstatus() is undefined when the
				// worker died from a signal and would often read as 0.
				$signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null;
				$exitCode = 1;
				$output->writeln('<error>Worker ' . $index . ' did not exit normally' .
					(is_null($signal) ? '' : ', killed by signal ' . $signal) . '</error>');
			}

			if ($exitCode !== 0) {
				$workerFailed = true;
			}
		}

		return $workerFailed ? 1 : 0;
	}
}

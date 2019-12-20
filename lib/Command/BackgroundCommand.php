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
use OCP\IUserManager;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\BackgroundJob\BackgroundService;

class BackgroundCommand extends Command {

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
				'timeout',
				't',
				InputOption::VALUE_REQUIRED,
				'Sets timeout in seconds for this command. Default is without timeout, e.g. command runs indefinitely.',
				0
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->backgroundService->setLogger($output);

		// Extract user, if any
		//
		$userId = $input->getOption('user_id');
		$user = null;

		if (!is_null($userId)) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				throw new \InvalidArgumentException("User with id <$userId> in unknown.");
			}
		}

		// Extract timeout
		//
		$timeout = $input->getOption('timeout');
		if (!is_null($timeout)) {
			if ($timeout < 0) {
				throw new \InvalidArgumentException("Timeout must be positive value in seconds.");
			}
		} else {
			$timeout = 0;
		}

		// Extract max image area
		//
		$maxImageArea = $input->getOption('max_image_area');
		if (!is_null($maxImageArea)) {
			$maxImageArea = intval($maxImageArea);

			if ($maxImageArea === 0) {
				throw new \InvalidArgumentException("Max image area must be positive number.");
			}

			if ($maxImageArea < 0) {
				throw new \InvalidArgumentException("Max image area must be positive value.");
			}
		}

		// Extract verbosity (for command, we don't need this, but execute asks for it, if running from cron job).
		//
		$verbose = $input->getOption('verbose');

		// Main thing
		//
		$this->backgroundService->execute($timeout, $verbose, $user, $maxImageArea);

		return 0;
	}
}

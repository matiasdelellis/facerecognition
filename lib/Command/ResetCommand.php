<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2019, Branko Kokanovic <branko@kokanovic.org>
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

use OCP\IUserManager;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\Service\FaceManagementService;

class ResetCommand extends Command {

	/** @var FaceManagementService */
	protected $faceManagementService;

	/** @var IUserManager */
	protected $userManager;

	/**
	 * @param FaceManagementService $faceManagementService
	 * @param IUserManager $userManager
	 */
	public function __construct(FaceManagementService $faceManagementService,
	                            IUserManager          $userManager) {
		parent::__construct();

		$this->faceManagementService = $faceManagementService;
		$this->userManager = $userManager;
	}

	protected function configure() {
		$this
			->setName('face:reset')
			->setDescription(
				'Resets and deletes everything. Good for starting over. ' .
				'BEWARE: Next runs of face:background_job will re-analyze all images.')
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Resets data for a given user only. If not given, resets everything for all users.',
				null
			)
			->addOption(
				'all',
				null,
				InputOption::VALUE_NONE,
				'Reset everything.',
				null
			)
			->addOption(
				'image-errors',
				null,
				InputOption::VALUE_NONE,
				'Reset errors in images to re-analyze again',
				null
			)
			->addOption(
				'clustering',
				null,
				InputOption::VALUE_NONE,
				'Just reset the clustering.',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		// Extract user, if any
		//
		$userId = $input->getOption('user_id');
		$user = null;

		if (!is_null($userId)) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$output->writeln("User with id <$userId> in unknown.");
				return 1;
			}
		}

		// Main thing
		//
		if ($input->getOption('all')) {
			$this->resetAll($user);
			$output->writeln('Reset successfully done');
			return 0;
		}
		else if ($input->getOption('image-errors')) {
			$this->resetImageErrors($user);
			$output->writeln('Reset image errors done');
			return 0;
		}
		else if ($input->getOption('clustering')) {
			$this->resetClusters($user);
			$output->writeln('Reset clustering done');
			return 0;
		}
		else {
			$output->writeln('You must specify what you want to reset');
			return 1;
		}
	}

	private function resetClusters($user) {
		$this->faceManagementService->resetClusters($user);
	}

	private function resetImageErrors($user) {
		$this->faceManagementService->resetImageErrors($user);
	}

	private function resetAll($user) {
		$this->faceManagementService->resetAll($user);
	}

}

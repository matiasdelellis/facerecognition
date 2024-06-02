<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;

class StatsCommand extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var ImageMapper */
	protected $imageMapper;

	/** @var FaceMapper */
	protected $faceMapper;

	/** @var PersonMapper */
	protected $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param IUserManager $userManager
	 * @param ImageMapper $imageMapper
	 * @param FaceMapper $faceMapper
	 * @param PersonMapper $personMapper
	 * @param SettingsService $settingsService
	 */
	public function __construct(IUserManager    $userManager,
	                            ImageMapper     $imageMapper,
	                            FaceMapper      $faceMapper,
	                            PersonMapper    $personMapper,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->userManager     = $userManager;
		$this->imageMapper     = $imageMapper;
		$this->faceMapper      = $faceMapper;
		$this->personMapper    = $personMapper;
		$this->settingsService = $settingsService;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:stats')
			->setDescription('Get a summary of statistics images, faces and persons')
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Get stats for a given user only. If not given, get stats for all users.',
				null
			)->addOption(
				'json',
				'j',
				InputOption::VALUE_NONE,
				'Print in a json format, useful to analyze it with another tool.',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$users = array();

		$userId = $input->getOption('user_id');
		if (!is_null($userId)) {
			if ($this->userManager->get($userId) === null) {
				$output->writeln("User with id <$userId> in unknown.");
				return 1;
			}
			else {
				$users[] = $userId;
			}
		}
		else {
			$this->userManager->callForAllUsers(function (IUser $iUser) use (&$users)  {
				$users[] = $iUser->getUID();
			});
		}

		if ($input->getOption('json')) {
			$this->printJsonStats($output, $users);
		}
		else {
			$this->printTabledStats($output, $users);
		}

		return 0;
	}

	private function printTabledStats(OutputInterface $output, array $users): void {

		$modelId = $this->settingsService->getCurrentFaceModel();

		$stats = array();
		foreach ($users as $user) {
			$stats[] = [
				$user,
				$this->imageMapper->countUserImages($user, $modelId),
				$this->imageMapper->countUserImages($user, $modelId, true),
				$this->faceMapper->countFaces($user, $modelId),
				$this->personMapper->countClusters($user, $modelId),
				$this->personMapper->countPersons($user, $modelId)
			];
		}

		$table = new Table($output);
		$table->setHeaders(['User', 'Images', 'Processed', 'Faces', 'Clusters', 'Persons'])->setRows($stats);
		$table->render();
	}

	private function printJsonStats(OutputInterface $output, array $users): void {

		$modelId = $this->settingsService->getCurrentFaceModel();

		$stats = array();
		foreach ($users as $user) {
			$stats[] = array(
				'user'     => $user,
				'images'   => $this->imageMapper->countUserImages($user, $modelId),
				'processed'=> $this->imageMapper->countUserImages($user, $modelId, true),
				'faces'    => $this->faceMapper->countFaces($user, $modelId),
				'clusters' => $this->personMapper->countClusters($user, $modelId),
				'persons'  => $this->personMapper->countPersons($user, $modelId)
			);
		}

		$output->writeln(json_encode($stats));
	}

}

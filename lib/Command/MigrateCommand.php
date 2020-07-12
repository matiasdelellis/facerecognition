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

use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FaceManagementService;

class MigrateCommand extends Command {

	/** @var FaceManagementService */
	protected $faceManagementService;

	/** @var IUserManager */
	protected $userManager;

	/** @var ModelManager */
	protected $modelManager;

	/** @var FaceMapper */
	protected $faceMapper;

	/**
	 * @param FaceManagementService $faceManagementService
	 * @param IUserManager $userManager
	 */
	public function __construct(FaceManagementService $faceManagementService,
	                            IUserManager          $userManager,
	                            ModelManager          $modelManager,
	                            FaceMapper            $faceMapper)
	{
		parent::__construct();

		$this->faceManagementService = $faceManagementService;
		$this->userManager           = $userManager;
		$this->modelManager          = $modelManager;
		$this->faceMapper            = $faceMapper;
	}

	protected function configure() {
		$this
			->setName('face:migrate')
			->setDescription(
				'Migrate the faces found in a model and analyze with the current model.')
			->addOption(
				'model',
				'm',
				InputOption::VALUE_REQUIRED,
				'The identifier number of the model to migrate',
				null,
			)
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Migrate data for a given user only. If not given, migrate everything for all users.',
				null,
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
		if ($userId === null) {
			$output->writeln("You must specify the user to migrate");
			return 1;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			$output->writeln("User with id <$userId> is unknown.");
			return 1;
		}

		$modelId = $input->getOption('model');
		if (is_null($modelId)) {
			$output->writeln("You must indicate the ID of the model to migrate");
			return 1;
		}

		$model = $this->modelManager->getModel($modelId);
		if (is_null($model)) {
			$output->writeln("Invalid model Id");
			return 1;
		}

		if (!$model->isInstalled()) {
			$output->writeln("The model <$modelId> is not installed");
			return 1;
		}

		$currentModel = $this->modelManager->getCurrentModel();
		$currentModelId = (!is_null($currentModel)) ? $currentModel->getId() : -1;

		if ($currentModelId === $modelId) {
			$output->writeln("The proposed model <$modelId> to migrate must be other than the current one <$currentModelId>");
			return 1;
		}

		if (!$this->faceManagementService->hasDataForUser($userId, $modelId)) {
			$output->writeln("The proposed model <$modelId> to migrate is empty");
			return 1;
		}

		if ($this->faceManagementService->hasDataForUser($userId, $modelId)) {
			$output->writeln("The current model <$currentModelId> already has data. You cannot migrate to a used model.");
			return 1;
		}


	}

}

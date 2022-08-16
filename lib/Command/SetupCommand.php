<?php
/**
 * @copyright Copyright (c) 2019-2020 Matias De lellis <mati86dl@gmail.com>
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

use OCA\FaceRecognition\Model\IModel;

use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Helper\CommandLock;
use OCA\FaceRecognition\Helper\MemoryLimits;

use OCP\Util as OCP_Util;

class SetupCommand extends Command {

	/** @var ModelManager */
	protected $modelManager;

	/** @var SettingsService */
	private $settingsService;

	/** @var OutputInterface */
	protected $logger;

	/**
	 * @param ModelManager $modelManager
	 * @param SettingsService $settingsService
	 */
	public function __construct(ModelManager    $modelManager,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->modelManager    = $modelManager;
		$this->settingsService = $settingsService;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:setup')
			->setDescription('Basic application settings, such as maximum memory, and the model used.')
			->addOption(
				'memory',
				'M',
				InputOption::VALUE_REQUIRED,
				'The maximum memory assigned for image processing',
				-1
			)
			->addOption(
				'model',
				'm',
				InputOption::VALUE_REQUIRED,
				'The identifier number of the model to install',
				-1
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->logger = $output;

		$assignMemory = $input->getOption('memory');
		$modelId = $input->getOption('model');

		if ($assignMemory < 0 && $modelId < 0) {
			$this->dumpCurrentSetup();
			return 0;
		}

		// Get lock to avoid potential errors.
		//
		$lock = CommandLock::Lock("face:setup");
		if (!$lock) {
			$output->writeln("Another command ('". CommandLock::IsLockedBy().  "') is already running that prevents it from continuing.");
			return 1;
		}

		if ($assignMemory > 0) {
			$ret = $this->setupAssignedMemory(OCP_Util::computerFileSize($assignMemory));
			if ($ret > 0) {
				CommandLock::Unlock($lock);
				return $ret;
			}
		}

		if ($modelId > 0) {
			$ret = $this->setupModel($modelId);
			if ($ret > 0) {
				CommandLock::Unlock($lock);
				return $ret;
			}
		}

		// Release obtained lock
		//
		CommandLock::Unlock($lock);

		return 0;
	}

	private function setupAssignedMemory ($assignMemory): int {
		$systemMemory = MemoryLimits::getSystemMemory();
		$this->logger->writeln("System memory: " . ($systemMemory > 0 ? $this->getHumanMemory($systemMemory) : "Unknown"));
		$phpMemory = MemoryLimits::getPhpMemory();
		$this->logger->writeln("Memory assigned to PHP: " . ($phpMemory > 0 ? $this->getHumanMemory($phpMemory) : "Unlimited"));

		$this->logger->writeln("");
		$availableMemory = MemoryLimits::getAvailableMemory();
		$this->logger->writeln("Minimum value to assign to image processing.: " . $this->getHumanMemory(SettingsService::MINIMUM_ASSIGNED_MEMORY));
		$this->logger->writeln("Maximum value to assign to image processing.: " . ($availableMemory > 0 ? $this->getHumanMemory($availableMemory) : "Unknown"));

		$this->logger->writeln("");
		if ($assignMemory > $availableMemory) {
			$this->logger->writeln("Cannot assign more memory than the maximum...");
			return 1;
		}

		if ($assignMemory < SettingsService::MINIMUM_ASSIGNED_MEMORY) {
			$this->logger->writeln("Cannot assign less memory than the minimum...");
			return 1;
		}

		$this->settingsService->setAssignedMemory ($assignMemory);
		$this->logger->writeln("Maximum memory assigned for image processing: " . $this->getHumanMemory($assignMemory));

		return 0;
	}

	private function setupModel (int $modelId): int {
		$this->logger->writeln("");

		$model = $this->modelManager->getModel($modelId);
		if (is_null($model)) {
			$this->logger->writeln('Invalid model Id');
			return 1;
		}

		$modelDescription = $model->getId() . ' (' . $model->getName(). ')';

		$error_message = "";
		if (!$model->meetDependencies($error_message)) {
			$this->logger->writeln('You do not meet the dependencies to install the model ' . $modelDescription);
			$this->logger->writeln('Summary: ' . $error_message);
			$this->logger->writeln('Please read the documentation for this model to continue: ' .$model->getDocumentation());
			return 1;
		}

		if ($model->isInstalled()) {
			$this->logger->writeln('The files of model ' . $modelDescription . ' are already installed');
			$this->modelManager->setDefault($modelId);
			$this->logger->writeln('The model ' . $modelDescription . ' was configured as default');

			return 0;
		}

		$this->logger->writeln('The model ' . $modelDescription . ' will be installed');
		$model->install();
		$this->logger->writeln('Install model ' . $modelDescription . ' successfully done');

		$this->modelManager->setDefault($modelId);
		$this->logger->writeln('The model ' . $modelDescription . ' was configured as default');

		return 0;
	}

	private function dumpCurrentSetup (): void {
		$this->logger->writeln("Current setup:");
		$this->logger->writeln('');
		$this->logger->writeln("Minimum value to assign to image processing.: " . $this->getHumanMemory(SettingsService::MINIMUM_ASSIGNED_MEMORY));
		$availableMemory = MemoryLimits::getAvailableMemory();
		$this->logger->writeln("Maximum value to assign to image processing.: " . ($availableMemory > 0 ? $this->getHumanMemory($availableMemory) : "Unknown"));
		$assignedMemory = $this->settingsService->getAssignedMemory();
		$this->logger->writeln("Maximum memory assigned for image processing: " . ($assignedMemory > 0 ? $this->getHumanMemory($assignedMemory) : "Pending configuration"));

		$this->logger->writeln('');
		$this->logger->writeln("Available models:");
		$table = new Table($this->logger);
		$table->setHeaders(['Id', 'Enabled', 'Name', 'Description']);

		$currentModel = $this->modelManager->getCurrentModel();
		$modelId = (!is_null($currentModel)) ? $currentModel->getId() : -1;

		$models = $this->modelManager->getAllModels();
		foreach ($models as $model) {
			$table->addRow([
				$model->getId(),
				($model->getId() === $modelId) ? '*' : '',
				$model->getName(),
				$model->getDescription()
			]);
		}
		$table->render();
	}

	private function getHumanMemory ($memory): string {
		return OCP_Util::humanFileSize($memory) . " (" . intval($memory) . "B)";
	}

}

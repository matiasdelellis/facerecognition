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
use Symfony\Component\Console\Style\SymfonyStyle;

use OCA\FaceRecognition\Model\IModel;

use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Helper\CommandLock;
use OCA\FaceRecognition\Helper\MemoryLimits;
use OCA\FaceRecognition\Model\ExternalModel\ExternalModel;

use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingWithMultipleExternalModelInstancesTask;

use OCP\Util as OCP_Util;

class SetupCommand extends Command {

	/** @var ModelManager */
	protected $modelManager;

	/** @var SettingsService */
	private $settingsService;

	/** @var OutputInterface */
	protected $logger;

	/** @var SymfonyStyle */
	protected $io;

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
				'show',
				's',
				InputOption::VALUE_NONE,
				'Displays the currently configured setup. Note: this will display the setup status after all other options are processed.'
			)
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
			)
			->addOption(
				'external_model_url',
				'u',
				InputOption::VALUE_REQUIRED,
				'Path to the external model URL, e.g. "192.168.1.123:8080". If not port is given ,port 80 is assumed implicitly.',
				null
			)
			->addOption(
				'external_model_api_key',
				'k',
				InputOption::VALUE_REQUIRED,
				'The API key to access the external model (needs to be specified when setting up the external model instance). Note: when you provide an empty option argument (-k "") then you have the option to reset the API key to its default value "' . SettingsService::SYSTEM_EXTERNAL_MODEL_DEFAULT_API_KEY . '".',
				null
			)
			->addOption(
				'number_of_instances',
				'i',
				InputOption::VALUE_REQUIRED,
				'The number of external model instances that can be used for parallel image analysis. Has no effect if the selected model is != 5.',
				null
			)
			->addOption(
				'consecutive_ports',
				null,
				InputOption::VALUE_REQUIRED,
				'If true, then the external model instances [2, ..., N] will be accessed by extracting the port from the "external_model_url" and incrementing the port for each additional external model instance.',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->io = new SymfonyStyle($input, $output);

		$this->logger = $output;

		$show = $input->getOption('show');
		$assignMemory = $input->getOption('memory');
		$modelId = $input->getOption('model');
		$external_model_url = $input->getOption('external_model_url');
		$external_model_api_key = $input->getOption('external_model_api_key');
		$number_of_instances = $input->getOption('number_of_instances');
		$consecutive_ports = $input->getOption('consecutive_ports');


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
		
		if (!is_null($external_model_url)) {
			if(empty($external_model_url)) {
				$this->logger->writeln('NOTICE: empty value for option "external_model_url" ignored.');
			} {
				$this->settingsService->setExternalModelUrl($external_model_url);
			}
		}
		
		if (!is_null($external_model_api_key)) {
			if(empty($external_model_api_key)) {
				if($this->io->confirm('No API key given. Do you want to set the default API key "' . SettingsService::SYSTEM_EXTERNAL_MODEL_DEFAULT_API_KEY . '"?', false)) {
					$this->settingsService->setExternalModelApiKey(SettingsService::SYSTEM_EXTERNAL_MODEL_DEFAULT_API_KEY);
				} else {
					$this->io->note('Empty option "external_model_api_key" ignored.');
				}
			} else {
				$this->settingsService->setExternalModelApiKey($external_model_api_key);
			}
		}

		if (!is_null($number_of_instances)) {

			$number_of_instances = intval($number_of_instances);
			if($number_of_instances > 0) {
				$this->settingsService->setExternalModelNumberOfInstances($number_of_instances);
			}
		}

		if (!is_null($consecutive_ports)) {
			$this->settingsService->setExternalModelInstancesHaveConsecutivePorts(filter_var($consecutive_ports,FILTER_VALIDATE_BOOLEAN));
		}

		if ($show) {
			$this->dumpCurrentSetup();
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

		$io = $this->io;

		$io->title("Current setup:");

		$io->section('Memory:');
		$this->logger->writeln("  Minimum value to assign to image processing : " . $this->getHumanMemory(SettingsService::MINIMUM_ASSIGNED_MEMORY));
		$availableMemory = MemoryLimits::getAvailableMemory();
		$this->logger->writeln("  Maximum value to assign to image processing : " . ($availableMemory > 0 ? $this->getHumanMemory($availableMemory) : "Unknown"));
		$assignedMemory = $this->settingsService->getAssignedMemory();
		$this->logger->writeln("  Maximum memory assigned for image processing: " . ($assignedMemory > 0 ? $this->getHumanMemory($assignedMemory) : "Pending configuration"));
		$io->newLine(2);

		$io->section("Available models:");
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
		unset($model);
		$table->render();
		$io->newLine(2);


		/**
		 * Settings related to the External model.
		 */

		$io->section('External model options' . ($currentModel->getId() == ExternalModel::FACE_MODEL_ID ? '' : ' (no effect as the external model is not enabled)') . ':');
		
		// get frequently referenced settings
		$modelUrl = $this->settingsService->getExternalModelUrl();
		$nInstances = $this->settingsService->getExternalModelNumberOfInstances();
		$consecutivePorts = $this->settingsService->getExternalModelInstancesHaveConsecutivePorts();
		$apiKey = $this->settingsService->getExternalModelApiKey();

		// get port and print error message if needed
		$basePort = -1;
		$portRegexPattern = ImageProcessingWithMultipleExternalModelInstancesTask::PORT_REGEX_PATTERN;
		$matches = [];
		if(preg_match($portRegexPattern, $modelUrl, $matches)) {
			$basePort = $matches[2];
		} else {
			if($consecutivePorts) {
				$io->caution("    " . ($currentModel->getId() == ExternalModel::FACE_MODEL_ID ? 'CRITICAL' : 'ERROR') . ': The external model URL must explicitly specify a port when "consecutive_ports" is "true".');
			}
		}


		$this->logger->writeln("  URL: <info>$modelUrl</info>");
		if($apiKey === SettingsService::SYSTEM_EXTERNAL_MODEL_DEFAULT_API_KEY) {
			$io->note("  API key: WARNING: the default API key $apiKey is configured. This default value should not be used. Please set a proper API key in your external model. See https://github.com/matiasdelellis/facerecognition-external-model for more information.");
		} else {
			$this->logger->writeln('  API key: <info>[redacted]' . '</info>');
		}
		$this->logger->writeln("  Number of available instances: <info>$nInstances </info>");
		$this->logger->writeln("  Instances have consecutive ports: <info>" . ($consecutivePorts ? 'true' : 'false') . '</info>');
		if($basePort > 0 and $nInstances > 1 and $consecutivePorts) {
			$this->logger->writeln('  External model URLs that will be called for analysis:');
			for($i=0; $i < $nInstances; $i++) {
				$this->logger->writeln('    ' . $matches[1] . ":<comment>" . ($basePort+$i) . '</comment>' . (count($matches) > 3 ? $matches[3] : ''));
			}
		}
		
		$this->logger->writeln('');
	}

	private function getHumanMemory ($memory): string {
		return OCP_Util::humanFileSize($memory) . " (" . intval($memory) . "B)";
	}

}

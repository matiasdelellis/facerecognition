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

class SetupCommand extends Command {

	/** @var ModelManager */
	protected $modelManager;

	/** @var OutputInterface */
	protected $logger;

	/**
	 * @param ModelManager $modelManager
	 */
	public function __construct(ModelManager $modelManager)
	{
		parent::__construct();

		$this->modelManager = $modelManager;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:setup')
			->setDescription('Download and Setup the model used for the analysis')
			->addOption(
				'model',
				'm',
				InputOption::VALUE_OPTIONAL,
				'The identifier number of the model to install',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->logger = $output;

		$modelId = $input->getOption('model');
		if (is_null($modelId)) {
			$this->logger->writeln('You must indicate the ID of the model to install');
			$this->dumpModels();
			return 0;
		}

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

	/**
	 * Print list of models
	 *
	 * @return void
	 */
	private function dumpModels(): void {
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

}

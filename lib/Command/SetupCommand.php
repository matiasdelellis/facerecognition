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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\Model\DlibCnn5Model;

class SetupCommand extends Command {

	/** @var  DlibCnn5Model */
	protected $model;

	/** @var OutputInterface */
	protected $logger;

	/**
	 * @param DlibCnn5Model $model
	 */
	public function __construct(DlibCnn5Model $model) {
		parent::__construct();

		$this->model = $model;
	}

	protected function configure() {
		$this
			->setName('face:setup')
			->setDescription('Download and Setup the model 1 used for the analysis');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->logger = $output;

		$this->logger->writeln('We will install the model 1');

		if ($this->model->isInstalled()) {
			$this->logger->writeln('Model 1 files are already installed');
			return 0;
		}

		$this->model->install();
		$this->logger->writeln('Install models successfully done');

		$this->model->setDefault();
		$this->logger->writeln('The new model was configured as default');

		return 0;
	}

}

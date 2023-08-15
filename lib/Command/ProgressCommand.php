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

use OCP\IDateTimeFormatter;

use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;

class ProgressCommand extends Command {

	/** @var IDateTimeFormatter */
	protected $dateTimeFormatter;

	/** @var ImageMapper */
	protected $imageMapper;

	/** @var FaceMapper */
	protected $faceMapper;

	/** @var PersonMapper */
	protected $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param IDateTimeFormatter $dateTimeFormatter
	 * @param ImageMapper $imageMapper
	 * @param FaceMapper $faceMapper
	 * @param PersonMapper $personMapper
	 * @param SettingsService $settingsService
	 */
	public function __construct(IDateTimeFormatter $dateTimeFormatter,
	                            ImageMapper        $imageMapper,
	                            FaceMapper         $faceMapper,
	                            PersonMapper       $personMapper,
	                            SettingsService    $settingsService)
	{
		parent::__construct();

		$this->dateTimeFormatter = $dateTimeFormatter;
		$this->imageMapper       = $imageMapper;
		$this->faceMapper        = $faceMapper;
		$this->personMapper      = $personMapper;
		$this->settingsService   = $settingsService;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:progress')
			->setDescription('Get the progress of the analysis and an estimated time')
			->addOption(
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
		$modelId = $this->settingsService->getCurrentFaceModel();

		$totalImages = $this->imageMapper->countImages($modelId);
		$processedImages = $this->imageMapper->countProcessedImages($modelId);

		$remainingImages = $totalImages - $processedImages;

		$avgProcessingTime = $this->imageMapper->avgProcessingDuration($modelId);
		$estimatedSeconds = (int) ($remainingImages * $avgProcessingTime/1000);

		if ($input->getOption('json')) {
			$this->printJsonProgress($output, $totalImages, $remainingImages, $estimatedSeconds);
		} else {
			$this->printTabledProgress($output, $totalImages, $remainingImages, $estimatedSeconds);
		}
		return 0;
	}

	private function printTabledProgress(OutputInterface $output, $totalImages, $remainingImages, $estimatedSeconds): void {
		if ($estimatedSeconds) {
			$estimatedTime = $this->dateTimeFormatter->formatTimeSpan((time() + $estimatedSeconds));
		} else {
			$estimatedTime = '-';
		}

		$table = new Table($output);
		$table
			->setHeaders(['Images', 'Remaining', 'ETA'])
			->setRows([[strval($totalImages), strval($remainingImages), $estimatedTime]]);
		$table->render();
	}

	private function printJsonProgress(OutputInterface $output, $totalImages, $remainingImages, $estimatedSeconds): void {
		$stats[] = array(
			'images'    => $totalImages,
			'remaining' => $remainingImages,
			'eta'       => $estimatedSeconds
		);
		$output->writeln(json_encode($stats));
	}
}

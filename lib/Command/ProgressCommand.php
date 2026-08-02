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

use OCA\FaceRecognition\Service\SettingsService;

class ProgressCommand extends Command {

	/** @var IDateTimeFormatter */
	protected $dateTimeFormatter;

	/** @var ImageMapper */
	protected $imageMapper;

	/** @var FaceMapper */
	protected $faceMapper;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param IDateTimeFormatter $dateTimeFormatter
	 * @param ImageMapper $imageMapper
	 * @param FaceMapper $faceMapper
	 * @param SettingsService $settingsService
	 */
	public function __construct(IDateTimeFormatter $dateTimeFormatter,
	                            ImageMapper        $imageMapper,
	                            FaceMapper         $faceMapper,
	                            SettingsService    $settingsService)
	{
		parent::__construct();

		$this->dateTimeFormatter = $dateTimeFormatter;
		$this->imageMapper       = $imageMapper;
		$this->faceMapper        = $faceMapper;
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
		$refinementEnabled = $this->settingsService->getRefinementEnabled();

		$totalImages = $this->imageMapper->countImages($modelId);
		// The images that were not processed yet are the ones left for the fast
		// pass. Note that they can also be new images added to the library, which
		// the refinement will take at full quality.
		$pendingImages = $totalImages - $this->imageMapper->countProcessedImages($modelId);
		// The images that are left are the ones the analysis will take: with the
		// two passes, an image is only done when it was refined, unless it failed,
		// since a failed image is not taken again until the errors are reset.
		$remainingImages = $this->imageMapper->countRemainingImages($modelId, $refinementEnabled);
		// What is left is refinement, which costs much more than the fast pass,
		// so the estimate is made with the time the refined images took.
		$avgProcessingTime = $this->imageMapper->avgProcessingDuration($modelId, $refinementEnabled);
		$estimatedSeconds = (int) ($remainingImages * $avgProcessingTime/1000);

		if ($input->getOption('json')) {
			$this->printJsonProgress($output, $totalImages, $pendingImages, $remainingImages, $estimatedSeconds);
		} else {
			$this->printTabledProgress($output, $totalImages, $pendingImages, $remainingImages, $estimatedSeconds, $refinementEnabled);
		}
		return 0;
	}

	private function printTabledProgress(OutputInterface $output, $totalImages, $pendingImages, $remainingImages, $estimatedSeconds, $refinementEnabled): void {
		if ($estimatedSeconds) {
			$estimatedTime = $this->dateTimeFormatter->formatTimeSpan((time() + $estimatedSeconds));
		} else {
			$estimatedTime = '-';
		}

		// Without the refinement there is a single pass, and then the images
		// pending for it are all the images that are left.
		$headers = ['Images'];
		$row = [strval($totalImages)];
		if ($refinementEnabled) {
			$headers[] = 'Pending';
			$row[] = strval($pendingImages);
		}
		$headers[] = 'Remaining';
		$row[] = strval($remainingImages);
		$headers[] = 'ETA';
		$row[] = $estimatedTime;

		$table = new Table($output);
		$table
			->setHeaders($headers)
			->setRows([$row]);
		$table->render();
	}

	private function printJsonProgress(OutputInterface $output, $totalImages, $pendingImages, $remainingImages, $estimatedSeconds): void {
		$stats[] = array(
			'images'    => $totalImages,
			'pending'   => $pendingImages,
			'remaining' => $remainingImages,
			'eta'       => $estimatedSeconds
		);
		$output->writeln(json_encode($stats));
	}
}

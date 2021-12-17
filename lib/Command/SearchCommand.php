<?php
/**
 * @copyright Copyright (c) 2021, Matias De lellis <mati86dl@gmail.com>
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

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

class SearchCommand extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var ImageMapper */
	protected $imageMapper;

	/** @var FaceMapper */
	protected $faceMapper;

	/** @var FileService */
	private $fileService;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param IUserManager $userManager
	 * @param ImageMapper $imageMapper
	 * @param FaceMapper $faceMapper
	 * @param SettingsService $settingsService
	 * @param FileService $fileService
	 */
	public function __construct(IUserManager    $userManager,
	                            ImageMapper     $imageMapper,
	                            FaceMapper      $faceMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->userManager     = $userManager;
		$this->imageMapper     = $imageMapper;
		$this->faceMapper      = $faceMapper;
		$this->fileService     = $fileService;
		$this->settingsService = $settingsService;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:search')
			->setDescription('Search for additional information thanks to the processed data')
			->addOption(
				'duplicates',
				null,
				InputOption::VALUE_NONE,
				'Search for duplicate images thanks to finding exactly the same faces',
				null
			)
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Search for a given user only. If not given, search for all users.',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		if (version_compare(phpversion('pdlib'), '1.0.2', '<')) {
			$output->writeln("The version of pdlib is very old. pdlib >= 1.0.2 is recommended");
			return 1;
		}

		if (!$input->getOption('duplicates')) {
			$output->writeln("You must indicate that you want to search.");
			return 1;
		}

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

		$this->searchDuplicates($output, $users);

		return 0;
	}

	private function searchDuplicates (OutputInterface $output, array $users): void {
		$sensitivity = 0.1;
		$min_confidence = $this->settingsService->getMinimumConfidence();
		$min_face_size = $this->settingsService->getMinimumFaceSize();
		$modelId = $this->settingsService->getCurrentFaceModel();

		foreach ($users as $user) {
			$duplicates = array();
			$faces = $this->faceMapper->getFaces($user, $modelId);
			$faces_count = count($faces);
			for ($i = 0; $i < $faces_count; $i++) {
				$face1 = $faces[$i];
				if ((!$face1->isGroupable) ||
				    ($face1->confidence < $min_confidence) ||
				    (max($face1->height(), $face1->width()) < $min_face_size)) {
					continue;
				}
				for ($j = $i+1; $j < $faces_count; $j++) {
					$face2 = $faces[$j];
					if ((!$face2->isGroupable) ||
					    ($face2->confidence < $min_confidence) ||
					    (max($face2->height(), $face2->width()) < $min_face_size)) {
						continue;
					}
					$distance = dlib_vector_length($face1->descriptor, $face2->descriptor);
					if ($distance < $sensitivity) {
						if (!isset($duplicates[$face1->getImage()])) {
							$duplicates[$face1->getImage()] = array();
						}
						if (!isset($duplicates[$face1->getImage()][$face2->getImage()])) {
							$duplicates[$face1->getImage()][$face2->getImage()] = 0;
						}
						$duplicates[$face1->getImage()][$face2->getImage()]++;
					}
				}
			}

			foreach ($duplicates as $image1 => $duplicate) {
				$image = $this->imageMapper->find($user, $image1);
				$file1 = $this->fileService->getFileById($image->getFile(), $user);
				$imagePath1 = $this->fileService->getLocalFile($file1);
				foreach ($duplicate as $image2 => $score) {
					$image = $this->imageMapper->find($user, $image2);
					$file2 = $this->fileService->getFileById($image->getFile(), $user);
					$imagePath2 = $this->fileService->getLocalFile($file2);
					$output->writeln("");
					$output->writeln("Duplicate: " . $score . " matches");
					$output->writeln(" File 1: " . $imagePath1);
					$output->writeln(" File 2: " . $imagePath2);
				}
			}
		}

	}

}

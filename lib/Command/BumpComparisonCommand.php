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

use OCP\Image as OCP_Image;
use OCP\Files\IRootFolder;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Service\SettingsService;


use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetectionMapper;

class BumpComparisonCommand extends Command {

	protected $userManager;
	protected $rootFolder;
	protected $personMapper;
	protected $faceMapper;
	protected $imageMapper;
	protected $settingsService;
	protected $faceDetections;
	protected $clusterMapper;

	public function __construct(
		IUserManager    $userManager,
		IRootFolder     $rootFolder,
		PersonMapper    $personMapper,
		FaceMapper      $faceMapper,
		ImageMapper     $imageMapper,
		SettingsService $settingsService,
		FaceDetectionMapper $faceDetections,
		FaceClusterMapper $clusterMapper)
	{
		parent::__construct();

		$this->userManager     = $userManager;
		$this->rootFolder      = $rootFolder;
		$this->personMapper    = $personMapper;
		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
		$this->settingsService = $settingsService;
		$this->faceDetections  = $faceDetections;
		$this->clusterMapper   = $clusterMapper;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:compare')
			->setDescription('Compare the results of our application with Recognize...')
			->addOption(
				'output',
				'o',
				InputOption::VALUE_REQUIRED,
				'Folder where to write the results',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$outputFolder = $input->getOption('output');

		if (file_exists($outputFolder))
			$this->removeDirectory($outputFolder);
		mkdir ($outputFolder);

		$users = array();
		$this->userManager->callForAllUsers(function (IUser $iUser) use (&$users)  {
			$users[] = $iUser->getUID();
		});

		$appFolder = $outputFolder . '/FaceRecognition';
		mkdir ($appFolder);

		$modelId = $this->settingsService->getCurrentFaceModel();
		foreach ($users as $userId) {
			$clusters = $this->personMapper->findAll($userId, $modelId);
			foreach ($clusters as $cluster) {
				$clusterId = $cluster->getId();
				$clusterFaces = $this->faceMapper->findFromCluster($userId, $clusterId, $modelId);
				$clusterCount = count($clusterFaces);

				$clusterFolder = $appFolder . '/' . $clusterCount . ' faces - Cluster Id ' .  $clusterId;
				mkdir($clusterFolder);

				foreach ($clusterFaces as $face) {
					$image = $this->imageMapper->find($userId, $face->getImage());
					$fileId = $image->getFile();

					$x = $face->getLeft ();
					$y = $face->getTop ();
					$w = $face->getRight () - $x;
					$h = $face->getBottom () - $y;

					$thumb = $this->getThumb($userId, $fileId, $x, $y, $w, $h, 48);
					$facePath = $clusterFolder . '/Face Id ' . $face->getId() . '.jpeg';
					$thumb->save($facePath, 'image/jpeg');
				}
			}
		}

		$appFolder = $outputFolder . '/Recognize';
		mkdir ($appFolder);

		foreach ($users as $userId) {
			$clusters = $this->clusterMapper->findByUserId($userId);
			foreach ($clusters as $cluster) {
				$clusterId = $cluster->getId();
				$clusterFaces = $this->faceDetections->findByClusterId($clusterId);
				$clusterCount = count($clusterFaces);

				$clusterFolder = $appFolder . '/' . $clusterCount . ' faces - Cluster Id ' .  $clusterId;
				mkdir($clusterFolder);

				foreach ($clusterFaces as $face) {
					$fileId = $face->getFileId();
					list($width, $height) = $this->getImageSize($userId, $fileId);

					$x = $face->getX ()*$width;
					$y = $face->getY ()*$height;
					$w = $face->getWidth()*$width;
					$h = $face->getHeight()*$height;

					$thumb = $this->getThumb($userId, $fileId, $x, $y, $w, $h, 48);
					$facePath = $clusterFolder . '/Face Id ' . $face->getId() . '.jpeg';
					$thumb->save($facePath, 'image/jpeg');
				}
			}

			$clusterFaces = $this->faceDetections->findByUserId($userId);
			$clusterFaces = array_filter ($clusterFaces, function($face) {
				return $face->getClusterId() == null;
			});
			$clusterCount = count($clusterFaces);
			$clusterFolder = $appFolder . '/' . $clusterCount . ' faces without Cluster';
			mkdir($clusterFolder);

			foreach ($clusterFaces as $face) {
				$fileId = $face->getFileId();
				list($width, $height) = $this->getImageSize($userId, $fileId);

				$x = $face->getX ()*$width;
				$y = $face->getY ()*$height;
				$w = $face->getWidth()*$width;
				$h = $face->getHeight()*$height;

				$thumb = $this->getThumb($userId, $fileId, $x, $y, $w, $h, 48);
				$facePath = $clusterFolder . '/Face Id ' . $face->getId() . '.jpeg';
				$thumb->save($facePath, 'image/jpeg');
			}
		}

		return 0;
	}

	private function removeDirectory($path) {

		$files = glob($path . '/*');
		foreach ($files as $file) {
			is_dir($file) ? $this->removeDirectory($file) : unlink($file);
		}
		rmdir($path);
		return;
	}

	private function getImageSize ($userId, $fileId) {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$file = $nodes[0];

		$ownerView = new \OC\Files\View('/'. $userId . '/files');
		$path = $userFolder->getRelativePath($file->getPath());

		$fileName = $ownerView->getLocalFile($path);
		return getimagesize($fileName);
	}

	private function getThumb ($userId, $fileId, $x, $y, $w, $h, $size) {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$file = $nodes[0];

		$ownerView = new \OC\Files\View('/'. $userId . '/files');
		$path = $userFolder->getRelativePath($file->getPath());

		$img = new OCP_Image();
		$fileName = $ownerView->getLocalFile($path);
		$img->loadFromFile($fileName);
		$img->fixOrientation();

		$padding = $h*0.25;
		$x -= $padding;
		$y -= $padding;
		$w += $padding*2;
		$h += $padding*2;

		$img->crop($x, $y, $w, $h);
		$img->scaleDownToFit($size, $size);

		return $img;
	}

}

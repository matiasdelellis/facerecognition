<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Matias De lellis <mati86dl@gmail.om>
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

use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Helper\PythonAnalyzer;

class Analyze extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var IRootFolder */
	protected $rootFolder;

	/** @var IConfig */
	protected $config;

	/** @var String */
	protected $dataDir;

	/** @var String */
	protected $command;

	/** @var String */
	protected $landmarksModel;

	/** @var String */
	protected $recognitionModel;

	/** @var OutputInterface */
	protected $output;

	/** @var int[][] */
	protected $sizes;

	/** @var IManager */
	protected $encryptionManager;

	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var FaceMapper */
	protected $faceMapper;

	/** @var int */
	protected $globalCount;

	/**
	 * @param IRootFolder $rootFolder
	 * @param IUserManager $userManager
	 * @param IConfig $config
	 * @param IManager $encryptionManager
	 * @param FaceMapper $faceMapper
	 */
	public function __construct(IRootFolder $rootFolder,
		                    IUserManager $userManager,
		                    IConfig $config,
		                    IManager $encryptionManager,
		                    IAppManager $appManager,
		                    FaceMapper $faceMapper) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->encryptionManager = $encryptionManager;
		$this->appManager = $appManager;
		$this->faceMapper = $faceMapper;

		$this->dataDir = rtrim($this->config->getSystemValue('datadirectory', \OC::$SERVERROOT.'/data'), '/');
	}

	protected function configure() {
		$this
			->setName('face:analyze')
			->setDescription('Analyze pictures to find faces')
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL,
				'Analyze faces for the given user'
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->output = $output;

		if ($this->encryptionManager->isEnabled()) {
			$this->output->writeln('Encryption is enabled. Aborted.');
			return 1;
		}

		$req = new Requirements($this->appManager, -1);

		if (!$req->pdlibLoaded())
			$this->output->writeln('pdlib extension is not loaded. Try to use python helper.');

		$this->command = $req->getPythonHelper();
		if (!$this->command) {
			$this->output->writeln('nextcloud-face-recognition-cmd not found. Aborted.');
			return 1;
		}

		$this->recognitionModel = $req->getRecognitionModel();
		if (!$this->recognitionModel) {
			$this->output->writeln('Recognition Model not found. Aborted.');
			return 1;
		}

		$this->landmarksModel = $req->getLandmarksModel();
		if (!$this->landmarksModel) {
			$this->output->writeln('Landmarks Model not found. Aborted.');
			return 1;
		}

		if ($this->checkAlreadyRunning()) {
			$output->writeln('Command is already running.');
			return 2;
		}

		$this->setPID();
		$this->setStartTime(time());

		$userId = $input->getArgument('user_id');
		if ($userId === null) {
			$this->userManager->callForSeenUsers(function (IUser $user) {
				$this->appendNewUserPictures($user);
			});
		} else {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
			    $this->appendNewUserPictures($user);
			}
		}

		$this->clearPID();
		$this->updateProgress(0);
		$this->setStartTime(0);

		return 0;
	}

	protected function appendNewFaces($face_location) {
		$fullPath = $face_location->{'filename'};
		$path = str_replace($this->dataDir, '', $fullPath);

		$absPath = ltrim($path, '/');
		$uid = explode('/', $absPath)[0];

		$fileId = $this->rootFolder->get($path)->getId();

		$dbFace = $this->faceMapper->findNewFile($uid, $fileId);
		if ($dbFace != null) {
			$dbFace[0]->setName($face_location->{'name'});
			$dbFace[0]->setDistance($face_location->{'distance'});
			$dbFace[0]->setTop($face_location->{'top'});
			$dbFace[0]->setRight($face_location->{'right'});
			$dbFace[0]->setBottom($face_location->{'bottom'});
			$dbFace[0]->setLeft($face_location->{'left'});
			if ($face_location->{'encoding'} !== null)
				$dbFace[0]->setEncoding(serialize($face_location->{'encoding'}));
			$this->faceMapper->update($dbFace[0]);
		} else {
			$dbFace = new Face();
			$dbFace->setUid($uid);
			$dbFace->setFile($fileId);
			$dbFace->setName($face_location->{'name'});
			$dbFace->setDistance($face_location->{'distance'});
			$dbFace->setTop($face_location->{'top'});
			$dbFace->setRight($face_location->{'right'});
			$dbFace->setBottom($face_location->{'bottom'});
			$dbFace->setLeft($face_location->{'left'});
			if ($face_location->{'encoding'} !== null)
				$dbFace->setEncoding(serialize($face_location->{'encoding'}));
			$this->faceMapper->insert($dbFace);
		}
	}

	protected function appendNewUserPictures (IUser $user) {
		$userId = $user->getUID();
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$userRoot = $userFolder->getParent();
		} catch (NotFoundException $e) {
			return;
		}

		$this->output->writeln('');
		$this->output->writeln($userId.': Looking for images to analyze.');

		$queueSize = $this->faceMapper->countUserQueue($userId);
		if ($queueSize == 0) {
			$this->output->writeln('No new images to analyze. Skipping.');
			return;
		}

		$this->output->writeln($queueSize.' image(s) will be analyzed, please be patient..');

		//if (!$req->pdlibLoaded())
			$analyzer = new PythonAnalyzer ($this->command, $this->landmarksModel, $this->recognitionModel);
		// else
		//	$analyzer = new php-face ($this->landmarksModel, $this->recognitionModel);

		$facesFound = [];

		$chunks_size = 10;
		$offsets = $queueSize / $chunks_size;
		for ($offset = 0 ; $offset < $offsets ; $offset++) {
			$chunk_queue = $this->faceMapper->findAllQueued($userId, $chunks_size, $offset*$chunks_size);
			foreach ($chunk_queue as $face) {
				$file = $userRoot->getById($face->getFile());
				$analyzer->appendFile ($this->dataDir.$file[0]->getPath());
			}
			$chuck_result = $analyzer->analyze();

			foreach ($chuck_result as $newFace) {
				$facesFound[] = $newFace;
			}

			$this->globalCount += count($chunk_queue);
			$this->updateProgress($this->globalCount);
		}

		foreach ($facesFound as $newFace) {
			$this->appendNewFaces($newFace);
		}

		$this->output->writeln(count($facesFound).' faces(s) found.');
	}

	private function updateProgress($progress) {
		$this->config->setAppValue('facerecognition', 'queue-done', $progress);
	}

	private function setStartTime($time) {
		$this->config->setAppValue('facerecognition', 'starttime', $time);
	}

	private function setPID() {
		$this->config->setAppValue('facerecognition', 'pid', posix_getpid());
	}

	private function clearPID() {
		$this->config->deleteAppValue('facerecognition', 'pid');
	}

	private function getPID() {
		return (int)$this->config->getAppValue('facerecognition', 'pid', -1);
	}

	private function checkAlreadyRunning() {
		$pid = $this->getPID();
		// No PID set so just continue
		if ($pid === -1) {
			return false;
		}
		// Get get the gid of non running processes so continue
		if (posix_getpgid($pid) === false) {
			return false;
		}
		// Seems there is already a running process generating previews
		return true;
	}

}

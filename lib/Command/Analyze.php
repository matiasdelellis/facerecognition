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
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

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

		if ($this->command_exist('nextcloud-face-recognition-cmd')) {
			$this->command = 'nextcloud-face-recognition-cmd';
		}
		else if (file_exists($this->appManager->getAppPath('facerecognition').'/opt/bin/nextcloud-face-recognition-cmd')) {
			$this->command = $this->appManager->getAppPath('facerecognition').'/opt/bin/nextcloud-face-recognition-cmd';
		}
		else {
			$this->output->writeln('nextcloud-face-recognition-cmd not found. Aborted.');
			return 1;
		}

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

	protected function getUserKnownFolder(IUser $user) {
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		try {
			$knownFolder = $userFolder->get('.faces');
		} catch (NotFoundException $e) {
			$knownFolder = $userFolder->newFolder('.faces');
		}
		return $knownFolder;
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

		$faces = $this->faceMapper->findAllNew($userId);
		if ($faces == null) {
			$this->output->writeln('No new images to analyze. Skipping.');
			return;
		}

		$fileList = '';
		foreach ($faces as $face) {
			$file = $userRoot->getById($face->getFile());
			$fullPath = escapeshellarg($this->dataDir.$file[0]->getPath());
			$fileList .= " ".$fullPath;
		}

		$knownFolder = $this->getUserKnownFolder($user);
		$facesPath = $this->dataDir.$knownFolder->getPath();

		$this->output->writeln(count($faces).' image(s) will be analyzed, please be patient..');
		$cmd = $this->command.' analyze --search '.$fileList. ' --known '. $facesPath;
		$result = shell_exec ($cmd);

		$newFaces = json_decode ($result);
		$facesFound = $newFaces->{'faces-locations'};
		foreach ($facesFound as $newFace) {
			$this->appendNewFaces($newFace);
		}

		$this->output->writeln(count($facesFound).' faces(s) faces found.');
	}

	protected function command_exist ($cmd) {
		$return = shell_exec(sprintf("which %s", escapeshellarg($cmd)));
		return !empty($return);
	}

}

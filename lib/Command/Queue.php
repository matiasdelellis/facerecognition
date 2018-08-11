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
use OCP\Files\IRootFolder;
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

class Queue extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var IRootFolder */
	protected $rootFolder;

	/** @var IConfig */
	protected $config;

	/** @var OutputInterface */
	protected $output;

	/** @var int[][] */
	protected $sizes;

	/** @var IManager */
	protected $encryptionManager;

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
		                    FaceMapper $faceMapper) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->encryptionManager = $encryptionManager;
		$this->faceMapper = $faceMapper;
	}

	protected function configure() {
		$this
			->setName('face:queue')
			->setDescription('Get new image files and queue to the next analysis')
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL,
				'Queue image files for the given user'
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($this->encryptionManager->isEnabled()) {
			$output->writeln('Encryption is enabled. Aborted.');
			return 1;
		}

		$this->output = $output;

		$userId = $input->getArgument('user_id');
		if ($userId === null) {
			$this->userManager->callForSeenUsers(function (IUser $user) {
				$this->generateUserFaces($user);
			});
		} else {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$this->generateUserFaces($user);
			}
		}

		return 0;
	}

	/**
	 * @param IUser $user
	 */
	private function generateUserFaces(IUser $user) {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($user->getUID());

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$this->parseUserFolder($userFolder);
	}

	/**
	 * @param Folder $folder
	 * TODO: It is inefficient since it copies the array recursively.
	 */
	private function getPicturesFromFolder(Folder $folder, $results = array()) {
		//$this->output->writeln('Scanning folder ' . $folder->getPath());

		$nodes = $folder->getDirectoryListing();

		foreach ($nodes as $node) {
			//if ($node->isHidden())
			//	continue;
			if ($node instanceof Folder and !$node->nodeExists('.nomedia')) {
				$results = $this->getPicturesFromFolder($node, $results);
			} else if ($node instanceof File and $node->getMimeType() === 'image/jpeg') {
				$results[] = $node;
			}
		}

		return $results;
	}


	/**
	 * @param Folder $folder
	 */
	private function parseUserFolder(Folder $folder) {
		$nodes = $this->getPicturesFromFolder($folder);
		foreach ($nodes as $file) {
			if ($this->faceMapper->fileExists($file->getId()) == False) {
				$this->putFile ($file);
			}
		}
	}

	/**
	 * @param File $file
	 */
	private function putFile(File $file) {
		$absPath = ltrim($file->getPath(), '/');
		$owner = explode('/', $absPath)[0];

		if ($file->getMimeType() !== 'image/jpeg' && $file->getMimeType() !== 'image/png') {
			return;
		}

		$face = new Face();
		$face->setUid($owner);
		$face->setFile($file->getId());
		$face->setName('unknown');
		$face->setDistance(-1.0);
		$face->setTop(-1.0);
		$face->setRight(-1.0);
		$face->setBottom(-1.0);
		$face->setLeft(-1.0);
		$this->faceMapper->insert($face);

	}

}

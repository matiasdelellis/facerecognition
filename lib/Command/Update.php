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
use OCA\FaceRecognition\Helper\Euclidean;

class Update extends Command {

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
			->setName('face:update')
			->setDescription('Update clustering images from user')
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
		if ($this->encryptionManager->isEnabled()) {
			$output->writeln('Encryption is enabled. Aborted.');
			return 1;
		}

		$this->output = $output;

		$userId = $input->getArgument('user_id');
		if ($userId === null) {
			$this->userManager->callForSeenUsers(function (IUser $user) {
				$this->updateUserClusters($user);
			});
		} else {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$this->updateUserClusters($user);
			}
		}

		return 0;
	}

	/**
	 * @param IUser $user
	 */
	private function updateUserClusters(IUser $user) {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($user->getUID());

		$euclidean = new Euclidean();

		$userId = $user->getUID();
		$unknownFaces = $this->faceMapper->findAllUnknown($userId);
		$knownFaces = $this->faceMapper->findAllKnown($userId);

		$this->output->writeln('');
		$this->output->writeln($userId.' have '.count($knownFaces).' known faces and '.count($unknownFaces).' to clustering');

		$clustered = 0;
		foreach ($unknownFaces as $unknownFace) {
			$distance = 1.0;
			$bestDistance = 1.0;
			$bestFace = NULL;
			$unknownEncoding = unserialize ($unknownFace->getEncoding());
			foreach ($knownFaces as $knownFace) {
				$knownEncoding = unserialize ($knownFace->getEncoding());
				$distance = $euclidean->distance($unknownEncoding, $knownEncoding);
				if ($distance < $bestDistance) {
					$bestFace = $knownFace;
					$bestDistance = $distance;
				}
			}
			if ($bestDistance < 0.5) {
				$unknownFace->setName($bestFace->getName());
				$unknownFace->setDistance($bestDistance);
				$this->faceMapper->update($unknownFace);
				$clustered++;
			}
		}
		$this->output->writeln($clustered.' new faces were recognized');

	}

}

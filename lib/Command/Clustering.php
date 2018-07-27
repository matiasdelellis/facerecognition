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

class Clustering extends Command {

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
			->setName('face:clustering')
			->setDescription('Clustering images from user')
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
				$this->clusteringUserFaces($user);
			});
		} else {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$this->clusteringUserFaces($user);
			}
		}

		return 0;
	}

	/**
	 * @param IUser $user
	 */
	private function clusteringUserFaces(IUser $user) {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($user->getUID());

		$euclidean = new Euclidean();

		$userId = $user->getUID();
		$unknownFaces = $this->faceMapper->findAll($userId);
		$knownFaces = [];
		$personCount = 0;

		$this->output->writeln('');
		$this->output->writeln($userId.' have '.count($unknownFaces).' faces to clustering..');

		$i = 0;

		/* All nodes are assigned to a random class.
		 * The number of initial classes equals the number of nodes.
		 */
		foreach ($unknownFaces as $unknownFace) {
			$unknownFace->setName('Unknown-'.$i++);
			$unknownFace->setDistance(1.0);
		}

		/* Nodes are selected one by one in a random order
		 * Every node moves to the class which the given node connects with the less distance.
		 */

		shuffle($unknownFaces);
		foreach ($unknownFaces as $unknownFace) {
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
				// Set to existing class..
				$unknownFace->setName($bestFace->getName());
				$unknownFace->setDistance($bestDistance);
			}
			else {
				//Just use as new class..
				$personCount++;
				$unknownFace->setName('Person '.$personCount);
				$unknownFace->setDistance(0.0);
			}

			// Put as 'known' face..
			array_unshift($knownFaces, $unknownFace);
		}

		/* TODO: Update until converge? */

		/* Save changes.. */
		foreach ($knownFaces as $knownFace) {
			$this->faceMapper->update($knownFace);
		}

		$this->output->writeln('Result on '.$personCount.' clusters.');

	}

}

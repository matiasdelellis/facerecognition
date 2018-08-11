<?php
/**
 * @copyright Copyright (c) 2018, Matias De lellis <mati86dl@gmail.com>
 *
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

use OCP\App\IAppManager;
use OCP\Encryption\IManager;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Helper\Requirements;

class Check extends Command {

	/** @var OutputInterface */
	protected $output;

	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var IManager */
	protected $encryptionManager;

	/**
	 * @param IAppManager $appManager
	 * @param IManager $encryptionManager
	 */
	public function __construct(IAppManager $appManager,
	                            IManager $encryptionManager) {
		parent::__construct();

		$this->appManager = $appManager;
		$this->encryptionManager = $encryptionManager;
	}

	protected function configure() {
		$this
			->setName('face:check')
			->setDescription('Checks the status of dependencies and tools');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($this->encryptionManager->isEnabled()) {
			$output->writeln('Encryption is enabled. It is incompatible with this application');
			return 1;
		}

		$this->output = $output;

		$checkStatus = TRUE;

		$req = new Requirements($this->appManager);

		if ($req->pdlibLoaded()) {
			$this->output->writeln('');
			$this->output->writeln('pdlib extension is loaded. Cool, but unfortunately still we not use it. Soon it will be important. ;)');
		}

		$this->output->writeln('');
		$command = $req->getPythonHelper();
		if ($command) {
			$this->output->writeln('nextcloud-face-recognition-cmd was found. Let\'s try it');
			$result = shell_exec ($command.' status');
			$status = json_decode ($result);
			if ($status) {
				$this->output->writeln('It seems to work correctly with dlib v'.$status->{'dlib-version'});
			}
			else {
				$this->output->writeln('There was an error. Please execute \'nextcloud-face-recognition-cmd status\' and check the errors');
				$checkStatus = FALSE;
			}

		} else {
			$this->output->writeln('nextcloud-face-recognition-cmd not found.');
		}

		$this->output->writeln('');
		$landmarksModel = $req->getLandmarksModel();
		if ($landmarksModel) {
			$this->output->writeln('Landmarks Model: '.$landmarksModel);
		}
		else {
			$this->output->writeln('Landmarks Model not found. Download some of these models and uncompress inside the \'vendor/models/\' folder');
			$this->output->writeln(' - http://dlib.net/files/shape_predictor_5_face_landmarks.dat.bz2');
			$this->output->writeln(' - http://dlib.net/files/shape_predictor_68_face_landmarks.dat.bz2');
			$checkStatus = FALSE;
		}

		$this->output->writeln('');
		$recognitionModel = $req->getRecognitionModel();
		if ($recognitionModel) {
			$this->output->writeln('Recognition Model: '.$recognitionModel);
		}
		else {
			$this->output->writeln('Recognition  Model not found. Download these model and uncompress inside the \'vendor/models/\' folder');
			$this->output->writeln(' - http://dlib.net/files/dlib_face_recognition_resnet_model_v1.dat.bz2');
			$checkStatus = FALSE;
		}

		$this->output->writeln('');
		if ($checkStatus) {
			$this->output->writeln('It seems that you have everything to work correctly. Please see the documentation to continue.');
		}
		else {
			$this->output->writeln('It seems that there are errors, try to correct them and try again.');
		}

		return 0;
	}

}

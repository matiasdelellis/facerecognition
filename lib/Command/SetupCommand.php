<?php
/**
 * @copyright Copyright (c) 2019, Matias De lellis <mati86dl@gmail.com>
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

use OCP\ITempManager;
use OCP\App\IAppManager;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetupCommand extends Command {

	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var ITempManager */
	protected $tempManager;

	/** @var string */
	protected $tempFolder;

	/** @var string */
	protected $modelsFolder;

	/* @var  OutputInterface */
	protected $logger;

	/*
	 * Model 1
	 */
	private $detectorModelUrl = 'https://github.com/davisking/dlib-models/raw/94cdb1e40b1c29c0bfcaf7355614bfe6da19460e/mmod_human_face_detector.dat.bz2';
	private $detectorModel = 'mmod_human_face_detector.dat';

	private $resnetModelUrl = 'https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2';
	private $resnetModel = 'dlib_face_recognition_resnet_model_v1.dat';

	private $predictorModelUrl = 'https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2';
	private $predictorModel = 'shape_predictor_5_face_landmarks.dat';

	/**
	 * @param FaceManagementService $faceManagementService
	 * @param IUserManager $userManager
	 */
	public function __construct(IAppManager  $appManager,
	                            ITempManager $tempManager) {
		parent::__construct();

		$this->appManager = $appManager;
		$this->tempManager = $tempManager;
	}

	protected function configure() {
		$this
			->setName('face:setup')
			->setDescription('Download and Setup the model 1 used for the analysis');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->logger = $output;

		$this->tempFolder = $this->tempManager->getTemporaryFolder('/facerecognition/');
		$this->modelsFolder = $this->appManager->getAppPath('facerecognition') . '/vendor/models/1/';

		$this->downloadModel ($this->detectorModelUrl);
		$this->bunzip2 ($this->getDownloadedFile($this->detectorModelUrl), $this->getModelFile($this->detectorModel));

		$this->downloadModel ($this->resnetModelUrl);
		$this->bunzip2 ($this->getDownloadedFile($this->resnetModelUrl), $this->getModelFile($this->resnetModel));

		$this->downloadModel ($this->predictorModelUrl);
		$this->bunzip2 ($this->getDownloadedFile($this->predictorModelUrl), $this->getModelFile($this->predictorModel));

		$this->logger->writeln('Install models successfully done');
		die();

		$this->tempManager->clean();

		return 0;
	}

	/**
	 * Downloads the facereconition model to $/updater-$instanceid/downloads/$filename
	 *
	 * @throws \Exception
	 */
	private function downloadModel(string $url) {
		$this->logger->writeln('Downloading: ' . $url . ' on ' . $this->getDownloadedFile($url));

		$fp = fopen($this->getDownloadedFile($url), 'w+');
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_USERAGENT => 'Nextcloud Facerecognition Installer',
		]);

		if(curl_exec($ch) === false) {
			throw new \Exception('Curl error: ' . curl_error($ch));
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode !== 200) {
			$statusCodes = [
				400 => 'Bad request',
				401 => 'Unauthorized',
				403 => 'Forbidden',
				404 => 'Not Found',
				500 => 'Internal Server Error',
				502 => 'Bad Gateway',
				503 => 'Service Unavailable',
				504 => 'Gateway Timeout',
			];

			$message = 'Download failed';
			if(isset($statusCodes[$httpCode])) {
				$message .= ' - ' . $statusCodes[$httpCode] . ' (HTTP ' . $httpCode . ')';
			} else {
				$message .= ' - HTTP status code: ' . $httpCode;
			}

			$curlErrorMessage = curl_error($ch);
			if(!empty($curlErrorMessage)) {
				$message .= ' - curl error message: ' . $curlErrorMessage;
			}
			$message .= ' - URL: ' . htmlentities($url);

			throw new \Exception($message);
		}

		$info = curl_getinfo($ch);
		$this->logger->writeln("Download ".$info['size_download']." bytes");

		curl_close($ch);
		fclose($fp);
	}

	/**
	 * @param string $in
	 * @param string $out
	 * @desc uncompressing the file with the bzip2-extension
	 *
	 * @throws \Exception
	 */
	private function bunzip2 ($in, $out) {
		$this->logger->writeln('Decompresing: '.$in. ' on '.$out);
		$this->logger->writeln('');

		if (!file_exists ($in) || !is_readable ($in))
			throw new \Exception('The file '.$in.' not exists or is not readable');
		if ((!file_exists ($out) && !is_writeable (dirname ($out)) || (file_exists($out) && !is_writable($out)) ))
			throw new \Exception('The file '.$out.' exists or is not writable');

		$in_file = bzopen ($in, "r");
		$out_file = fopen ($out, "w");

		while ($buffer = bzread ($in_file, 4096)) {
			if($buffer === FALSE)
				throw new \Exception('Read problem:  ' . bzerrstr($in_file));
			if(bzerrno($in_file) !== 0)
				throw new \Exception('Compression problem: '. bzerrstr($in_file));
			fwrite ($out_file, $buffer, 4096);
		}

		bzclose ($in_file);
		fclose ($out_file);
	}

	private function getDownloadedFile (string $url): string {
		$file = $this->tempFolder . basename($url);
		return $file;
	}

	private function getModelFile (string $name): string {
		$file = $this->modelsFolder . $name;
		return $file;
	}

}

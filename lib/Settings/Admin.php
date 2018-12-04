<?php

namespace OCA\FaceRecognition\Settings;

use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;

class Admin implements ISettings {

	/** @var IConfig */
	protected $config;

	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var IL10N */
	protected $l;

	public function __construct(IConfig $config,
		                    IAppManager $appManager,
		                    IL10N $l) {
		$this->config = $config;
		$this->appManager = $appManager;
		$this->l = $l;
	}

	public function getPriority() {
		return 20;
	}

	public function getSection() {
		return 'overview';
	}

	public function getForm() {

		$pdlibLoaded = TRUE;
		$pdlibVersion = '0.0';
		$modelVersion = AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID;
		$modelPresent = TRUE;
		$resume = "";

		$modelVersion = intval($this->config->getAppValue('facerecognition', 'model', $modelVersion));

		$req = new Requirements($this->appManager, $modelVersion);

		if ($req->pdlibLoaded()) {
			$pdlibVersion = $req->pdlibVersion();
		}
		else {
			$resume .= 'The PHP extension PDlib is not loaded. Please configure this. ';
			$pdlibLoaded = FALSE;
		}

		if (!$req->modelFilesPresent()) {
			$resume .= 'The files of the models version ' . $modelVersion . ' were not found. ';
			$modelPresent = FALSE;
		}

		$params = [
			'pdlib-loaded' => $pdlibLoaded,
			'pdlib-version' => $pdlibVersion,
			'model-version' => $modelVersion,
			'model-present' => $modelPresent,
			'resume' => $resume,
		];

		return new TemplateResponse('facerecognition', 'admin', $params, '');

	}
}
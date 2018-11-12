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

		$requirements = TRUE;
		$msg = "";

		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$req = new Requirements($this->appManager, $model);

		if (!$req->pdlibLoaded()) {
			$requirements = FALSE;
			$msg += 'PDLib is not loaded. Configure it';
		}

		if (!$req->getFaceRecognitionModelv2()) {
			$recognitionModel = 'dlib_face_recognition_resnet_model_v1.dat not found';
			$requirements = FALSE;
		}
		else {
			$recognitionModel = 'dlib_face_recognition_resnet_model_v1.dat';
		}

		if (!$req->getLandmarksDetectionModelv2()) {
			$landmarkingModel = 'shape_predictor_5_face_landmarks.dat not found';
			$requirements = FALSE;
		}
		else {
			$landmarkingModel = 'shape_predictor_5_face_landmarks.dat';
		}
		if (!$req->getFaceDetectionModelv2()) {
			$detectionModel = 'mmod_human_face_detector.dat not found';
			$requirements = FALSE;
		}
		else {
			$detectionModel = 'mmod_human_face_detector.dat';
		}

		$params = [
			'recognition-model' => $recognitionModel,
			'landmarking-model' => $landmarkingModel,
			'detection-model' => $detectionModel,
			'requirements' => $requirements,
			'msg' => $msg,
		];

		return new TemplateResponse('facerecognition', 'admin', $params, '');

	}
}
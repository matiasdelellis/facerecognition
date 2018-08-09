<?php

namespace OCA\FaceRecognition\Helper;

use OCP\App\IAppManager;

class Requirements
{
	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	function __construct(IAppManager $appManager) {
		$this->appManager = $appManager;
	}

	public function pdlibLoaded ()
	{
		return extension_loaded ('pdlib');
	}

	public function getPythonHelper ()
	{
		if (file_exists('/bin/nextcloud-face-recognition-cmd') ||
		    file_exists('/usr/bin/nextcloud-face-recognition-cmd')) {
			return 'nextcloud-face-recognition-cmd';
		}
		else if (file_exists($this->appManager->getAppPath('facerecognition').'/opt/bin/nextcloud-face-recognition-cmd')) {
			return $this->appManager->getAppPath('facerecognition').'/opt/bin/nextcloud-face-recognition-cmd';
		}
		else {
			return NULL;
		}
	}

	public function getRecognitionModel ()
	{
		if (file_exists($this->appManager->getAppPath('facerecognition').'/vendor/models/dlib_face_recognition_resnet_model_v1.dat')) {
			return $this->appManager->getAppPath('facerecognition').'/vendor/models/dlib_face_recognition_resnet_model_v1.dat';
		}
		else {
			return NULL;
		}
	}

	public function getLandmarksModel ()
	{
		if (file_exists($this->appManager->getAppPath('facerecognition').'/vendor/models/shape_predictor_5_face_landmarks.dat')) {
			return $this->appManager->getAppPath('facerecognition').'/vendor/models/shape_predictor_5_face_landmarks.dat';
		}
		else if (file_exists($this->appManager->getAppPath('facerecognition').'/vendor/models/shape_predictor_68_face_landmarks.dat')) {
			return $this->appManager->getAppPath('facerecognition').'/vendor/models/shape_predictor_68_face_landmarks.dat';
		}
		else {
			return NULL;
		}
	}

}

<?php

namespace OCA\FaceRecognition\Helper;

use OCP\App\IAppManager;

class Requirements
{
	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var int ID of used model */
	private $model;

	public function __construct(IAppManager $appManager, int $model) {
		$this->appManager = $appManager;
		$this->model = $model;
	}

	public function pdlibLoaded() {
		return extension_loaded('pdlib');
	}

	public function modelFilesPresent(): bool {
		if ($this->model === 1) {
			$faceDetection = $this->getFaceDetectionModelv2();
			$landmarkDetection = $this->getLandmarksDetectionModelv2();
			$faceRecognition = $this->getFaceRecognitionModelv2();

			if (($faceDetection === NULL) || ($landmarkDetection === NULL) || ($faceRecognition === NULL)) {
				return false;
			} else {
				return true;
			}
		} else {
			// Since current app version only can handle model with ID=1,
			// we surely cannot check if files from other model exist
			return false;
		}
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

	public function getFaceRecognitionModelv2() {
		return $this->getModel1File('dlib_face_recognition_resnet_model_v1.dat');
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

	public function getLandmarksDetectionModelv2() {
		return $this->getModel1File('shape_predictor_5_face_landmarks.dat');
	}

	public function getFaceDetectionModelv2() {
		return $this->getModel1File('mmod_human_face_detector.dat');
	}

	/**
	 * Common getter to full path, for all files from model with ID = 1
	 *
	 * @param string $file File to check
	 * @return string|null Full path to file, or NULL if file is not found
	 */
	private function getModel1File(string $file) {
		if ($this->model !== 1) {
			return NULL;
		}

		$fullPath = $this->appManager->getAppPath('facerecognition') . '/models/1/' . $file;
		if (file_exists($fullPath)) {
			return $fullPath;
		} else {
			return NULL;
		}
	}

	/**
	 * Determines if FaceRecognition can work with a givem image type. This is determined as
	 * intersection of types that are supported in Nextcloud and types that are supported in DLIB.
	 *
	 * Dlib support can be found here:
	 * https://github.com/davisking/dlib/blob/9b82f4b0f65a2152b4a4243c15709e5cb83f7044/dlib/image_loader/load_image.h#L21
	 *
	 * Note that Dlib supports these if it is compiled with them only! (with libjpeg, libpng...)
	 *
	 * Based on that and the fact that Nextcloud is superset of these, these are supported image types.
	 *
	 * @param string $mimeType MIME type to check if it supported
	 * @return true if MIME type is supported, false otherwise
	 */
	public static function isImageTypeSupported(string $mimeType): bool {
		if (
				($mimeType === 'image/jpeg') or
				($mimeType === 'image/png') or
				($mimeType === 'image/bmp') or
				($mimeType === 'image/gif')) {
			return true;
		} else {
			return false;
		}
	}
}

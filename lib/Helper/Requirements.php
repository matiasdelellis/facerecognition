<?php
namespace OCA\FaceRecognition\Helper;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

class Requirements
{
	/** @var ModelService */
	protected $modelService;

	public function __construct(ModelService $modelService,
	                            int          $model) {
		$this->modelService = $modelService;

		$this->modelService->useModelVersion($model);
	}

	public function hasEnoughMemory() {
		$memory = MemoryLimits::getSystemMemory();
		return ($memory > SettingsService::MINIMUM_SYSTEM_MEMORY_REQUERIMENTS);
	}

	public function pdlibLoaded() {
		return extension_loaded('pdlib');
	}

	public function pdlibVersion() {
		if (!$this->pdlibLoaded())
			return '0.0';
		return phpversion ('pdlib');
	}

	public function modelFilesPresent(): bool {
		$faceDetection = $this->getFaceDetectionModel();
		$landmarkDetection = $this->getLandmarksDetectionModel();
		$faceRecognition = $this->getFaceRecognitionModel();

		if (($faceDetection === NULL) || ($landmarkDetection === NULL) || ($faceRecognition === NULL)) {
			return false;
		} else {
			return true;
		}
	}

	public function getFaceRecognitionModel() {
		return $this->getModel1File('dlib_face_recognition_resnet_model_v1.dat');
	}

	public function getLandmarksDetectionModel() {
		return $this->getModel1File('shape_predictor_5_face_landmarks.dat');
	}

	public function getFaceDetectionModel() {
		return $this->getModel1File('mmod_human_face_detector.dat');
	}

	/**
	 * Common getter to full path, for all files from model with ID = 1
	 *
	 * @param string $file File to check
	 * @return string|null Full path to file, or NULL if file is not found
	 */
	private function getModel1File(string $file) {
		$fullPath = $this->modelService->getModelPath($file);

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

<?php
/**
 * @copyright Copyright (c) 2021-2023, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
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

namespace OCA\FaceRecognition\Model\DlibHogModel;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Service\CompressionService;
use OCA\FaceRecognition\Service\DownloadService;
use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\IModel;

class DlibHogModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = 3;
	const FACE_MODEL_NAME = 'DlibHog';
	const FACE_MODEL_DESC = 'Dlib HOG Model which needs lower requirements';
	const FACE_MODEL_DOC = 'https://github.com/matiasdelellis/facerecognition/wiki/Models#model-3';

	/** This model practically does not consume memory. Directly set the limits. */
	const MAXIMUM_IMAGE_AREA = SettingsService::MAXIMUM_ANALYSIS_IMAGE_AREA;
	const MINIMUM_MEMORY_REQUIREMENTS = 128 * 1024 * 1024;

	const FACE_MODEL_BZ2_URLS = [
		'https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2',
		'https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2'
	];

	const FACE_MODEL_FILES = [
		'shape_predictor_5_face_landmarks.dat',
		'dlib_face_recognition_resnet_model_v1.dat'
	];

	const I_MODEL_PREDICTOR = 0;
	const I_MODEL_RESNET = 1;

	const PREFERRED_MIMETYPE = 'image/png';

	/** @var \FaceLandmarkDetection */
	private $fld;

	/** @var \FaceRecognition */
	private $fr;

	/** @var CompressionService */
	private $compressionService;

	/** @var DownloadService */
	private $downloadService;

	/** @var ModelService */
	private $modelService;

	/** @var SettingsService */
	private $settingsService;


	/**
	 * DlibCnnModel __construct.
	 *
	 * @param CompressionService $compressionService
	 * @param DownloadService $downloadService
	 * @param ModelService $modelService
	 * @param SettingsService $settingsService
	 */
	public function __construct(CompressionService $compressionService,
	                            DownloadService    $downloadService,
	                            ModelService       $modelService,
	                            SettingsService    $settingsService)
	{
		$this->compressionService = $compressionService;
		$this->downloadService    = $downloadService;
		$this->modelService       = $modelService;
		$this->settingsService    = $settingsService;
	}

	public function getId(): int {
		return static::FACE_MODEL_ID;
	}

	public function getName(): string {
		return static::FACE_MODEL_NAME;
	}

	public function getDescription(): string {
		return static::FACE_MODEL_DESC;
	}

	public function getDocumentation(): string {
		return static::FACE_MODEL_DOC;
	}

	public function isInstalled(): bool {
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]))
			return false;
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]))
			return false;
		return true;
	}

	public function meetDependencies(string &$error_message): bool {
		if (!extension_loaded('pdlib')) {
			$error_message = "The PDlib PHP extension is not loaded";
			return false;
		}
		if (!version_compare(phpversion('pdlib'), '1.0.1', '>=')) {
			$error_message = "The PDlib PHP extension version is too old";
			return false;
		}
		$assignedMemory = $this->settingsService->getAssignedMemory();
		if ($assignedMemory < 0) {
			$error_message = "Seems that you still have to configure the assigned memory for image processing.";
			return false;
		}
		if (MemoryLimits::getAvailableMemory() < static::MINIMUM_MEMORY_REQUIREMENTS) {
			$error_message = "Your system does not meet the minimum memory requirements";
			return false;
		}
		return true;
	}

	public function getMaximumArea(): int {
		return intval(self::MAXIMUM_IMAGE_AREA);
	}

	public function getPreferredMimeType(): string {
		return static::PREFERRED_MIMETYPE;
	}

	/**
	 * @return void
	 */
	public function install() {
		if ($this->isInstalled()) {
			return;
		}

		// Create main folder where install models.
		$this->modelService->prepareModelFolder($this->getId());

		/* Download and install models */
		$predictorModelBz2 = $this->downloadService->downloadFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_PREDICTOR]);
		$this->compressionService->decompress($predictorModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));

		$resnetModelBz2 = $this->downloadService->downloadFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_RESNET]);
		$this->compressionService->decompress($resnetModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));

		/* Clean temporary files */
		$this->downloadService->clean();
	}

	/**
	 * @return void
	 */
	public function open() {
		$this->fld = new \FaceLandmarkDetection($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));
		$this->fr = new \FaceRecognition($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));
	}

	public function detectFaces(string $imagePath, bool $compute = true): array {
		$faces_detected = dlib_face_detection($imagePath);
		foreach ($faces_detected as &$face) {
			// Add and fake higher confidense value sinse this model does not provide it.
			$face['detection_confidence'] = 1.1;

			if (!$compute)
				continue;

			$landmarks = $this->fld->detect($imagePath, $face);
			$descriptor = $this->fr->computeDescriptor($imagePath, $landmarks);

			$face['landmarks'] = $landmarks['parts'];
			$face['descriptor'] = $descriptor;
		}
		return $faces_detected;
	}

	public function compute(string $imagePath, array $face): array {
		$landmarks = $this->fld->detect($imagePath, $face);
		$descriptor = $this->fr->computeDescriptor($imagePath, $landmarks);

		$face['landmarks'] = $landmarks['parts'];
		$face['descriptor'] = $descriptor;

		return $face;
	}

}

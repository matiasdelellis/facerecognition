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

namespace OCA\FaceRecognition\Model\DlibCnnModel;

use OCA\FaceRecognition\Service\CompressionService;
use OCA\FaceRecognition\Service\DownloadService;
use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\IModel;

class DlibCnnModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = -1;
	const FACE_MODEL_NAME = "";
	const FACE_MODEL_DESC = "";
	const FACE_MODEL_DOC = "";

	/** Relationship between image size and memory consumed */
	const MEMORY_AREA_RELATIONSHIP = -1;
	const MINIMUM_MEMORY_REQUIREMENTS = -1;

	const FACE_MODEL_FILES = array();

	const PREFERRED_MIMETYPE = 'image/png';

	/** @var \CnnFaceDetection */
	private $cfd;

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
	                            DownloadService $downloadService,
	                            ModelService    $modelService,
	                            SettingsService $settingsService)
	{
		$this->compressionService = $compressionService;
		$this->downloadService    = $downloadService;
		$this->modelService     = $modelService;
		$this->settingsService  = $settingsService;
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
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES['detector']['filename']))
			return false;
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES['predictor']['filename']))
			return false;
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES['resnet']['filename']))
			return false;
		return true;
	}

	public function meetDependencies(string &$error_message): bool {
		if (!extension_loaded('pdlib')) {
			$error_message = "The PDlib PHP extension is not loaded";
			return false;
		}
		$availableMemory = $this->settingsService->getAssignedMemory();
		if ($availableMemory < 0) {
			$error_message = "Seems that you still have to configure the assigned memory for image processing.";
			return false;
		}
		if ($availableMemory < static::MINIMUM_MEMORY_REQUIREMENTS) {
			$error_message = "Your system does not meet the minimum memory requirements.";
			return false;
		}
		return true;
	}

	public function getMaximumArea(): int {
		$assignedMemory = $this->settingsService->getAssignedMemory();
		return intval($assignedMemory/static::MEMORY_AREA_RELATIONSHIP);
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
		$detectorModelBz2 = $this->downloadService->downloadFile(static::FACE_MODEL_FILES['detector']['url']);
		$this->compressionService->decompress($detectorModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES['detector']['filename']));

		$predictorModelBz2 = $this->downloadService->downloadFile(static::FACE_MODEL_FILES['predictor']['url']);
		$this->compressionService->decompress($predictorModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES['predictor']['filename']));

		$resnetModelBz2 = $this->downloadService->downloadFile(static::FACE_MODEL_FILES['resnet']['url']);
		$this->compressionService->decompress($resnetModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES['resnet']['filename']));

		/* Clean temporary files */
		$this->downloadService->clean();
	}

	/**
	 * @return void
	 */
	public function open() {
		$this->cfd = new \CnnFaceDetection($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES['detector']['filename']));
		$this->fld = new \FaceLandmarkDetection($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES['predictor']['filename']));
		$this->fr = new \FaceRecognition($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES['resnet']['filename']));
	}

	public function detectFaces(string $imagePath, bool $compute = true): array {
		$faces_detected = $this->cfd->detect($imagePath, 0);

		if (!$compute)
			return $faces_detected;

		foreach ($faces_detected as &$face) {
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

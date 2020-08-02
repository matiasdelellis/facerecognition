<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Model\DlibCnnHogModel;

use OCP\IDBConnection;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\IModel;

class DlibCnnHogModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = 4;
	const FACE_MODEL_NAME = "CnnHog5";
	const FACE_MODEL_DESC = "Default Cnn model with Hog validation, and 5 point landmarks preprictor";
	const FACE_MODEL_DOC = "";

	/** Relationship between image size and memory consumed */
	const MEMORY_AREA_RELATIONSHIP = 1 * 1024;
	const MINIMUM_MEMORY_REQUIREMENTS = 1 * 1024 * 1024 * 1024;

	const FACE_MODEL_BZ2_URLS = [
		'https://github.com/davisking/dlib-models/raw/94cdb1e40b1c29c0bfcaf7355614bfe6da19460e/mmod_human_face_detector.dat.bz2',
		'https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2',
		'https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2'
	];

	const FACE_MODEL_FILES = [
		'mmod_human_face_detector.dat',
		'shape_predictor_5_face_landmarks.dat',
		'dlib_face_recognition_resnet_model_v1.dat'
	];

	const I_MODEL_DETECTOR = 0;
	const I_MODEL_PREDICTOR = 1;
	const I_MODEL_RESNET = 2;

	const PREFERRED_MIMETYPE = 'image/png';

	/** @var \CnnFaceDetection */
	private $cfd;

	/** @var \FaceLandmarkDetection */
	private $fld;

	/** @var \FaceRecognition */
	private $fr;

	/** @var IDBConnection */
	private $connection;

	/** @var FileService */
	private $fileService;

	/** @var ModelService */
	private $modelService;

	/** @var SettingsService */
	private $settingsService;


	/**
	 * DlibCnnModel __construct.
	 *
	 * @param IDBConnection $connection
	 * @param FileService $fileService
	 * @param ModelService $modelService
	 * @param SettingsService $settingsService
	 */
	public function __construct(IDBConnection   $connection,
	                            FileService     $fileService,
	                            ModelService    $modelService,
	                            SettingsService $settingsService)
	{
		$this->connection       = $connection;
		$this->fileService      = $fileService;
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
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_DETECTOR]))
			return false;
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
		if (MemoryLimits::getAvailableMemory() < static::MINIMUM_MEMORY_REQUIREMENTS) {
			$error_message = "Your system does not meet the minimum memory requirements";
			return false;
		}
		return true;
	}

	public function getMaximumArea(): int {
		return intval(MemoryLimits::getAvailableMemory()/static::MEMORY_AREA_RELATIONSHIP);
	}

	public function getPreferredMimeType(): string {
		return static::PREFERRED_MIMETYPE;
	}

	public function install() {
		if ($this->isInstalled()) {
			return;
		}

		// Create main folder where install models.
		$this->modelService->prepareModelFolder($this->getId());

		/* Download and install models */
		$detectorModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_DETECTOR]);
		$this->fileService->bunzip2($detectorModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_DETECTOR]));

		$predictorModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_PREDICTOR]);
		$this->fileService->bunzip2($predictorModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));

		$resnetModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_RESNET]);
		$this->fileService->bunzip2($resnetModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));

		/* Clean temporary files */
		$this->fileService->clean();

		// Insert on database and enable it
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('facerecog_models')
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', $this->getId());
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		if ((int)$data[0] <= 0) {
			$query = $this->connection->getQueryBuilder();
			$query->insert('facerecog_models')
			->values([
				'id' => $query->createNamedParameter($this->getId()),
				'name' => $query->createNamedParameter($this->getName()),
				'description' => $query->createNamedParameter($this->getDescription())
			])
			->execute();
		}
	}

	public function open() {
		$this->cfd = new \CnnFaceDetection($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_DETECTOR]));
		$this->fld = new \FaceLandmarkDetection($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));
		$this->fr = new \FaceRecognition($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));
	}

	public function detectFaces(string $imagePath): array {
		$detectedFaces = [];

		$cnnFaces = $this->cfd->detect($imagePath, 0);
		$hogFaces = dlib_face_detection($imagePath);

		foreach ($cnnFaces as $proposedFace) {
			$detectedFaces[] = $this->validateFace($proposedFace, $hogFaces);
		}

		return $detectedFaces;
	}

	public function detectLandmarks(string $imagePath, array $rect): array {
		return $this->fld->detect($imagePath, $rect);
	}

	public function computeDescriptor(string $imagePath, array $landmarks): array {
		return $this->fr->computeDescriptor($imagePath, $landmarks);
	}

	private function validateFace($proposedFace, $validateFaces) {
		foreach ($validateFaces as $validateFace) {
			$overlayPercent = $this->getOverlayPercent($proposedFace, $validateFace);
			/**
			 * The weak link in our default model is the landmark detector that
			 * can't align profile faces correctly.
			 * The Hog detector also fails and cannot detect these faces.
			 *
			 * So, if Hog detects it (Overlay > 80%), we know that the landmark
			 * detector will do it too.
			 * Just return it.
			 */
			if ($overlayPercent > 0.8) {
				return $proposedFace;
			}
		}

		/**
		 * If Hog don't detect this face, they are probably in profile or rotated.
		 * These are bad to compare, so we lower the confidence, to avoid clustering.
		 */
		$confidence = $proposedFace['detection_confidence'];
		$proposedFace['detection_confidence'] = $confidence * 0.9;

		return $proposedFace;
	}

	private function getOverlayPercent($rectP, $rectV): float {
		// Proposed face rect
		$leftP = $rectP['left'];
		$rightP = $rectP['right'];
		$topP = $rectP['top'];
		$bottomP = $rectP['bottom'];

		// Validate face rect
		$leftV = $rectV['left'];
		$rightV = $rectV['right'];
		$topV = $rectV['top'];
		$bottomV = $rectV['bottom'];

		// If one rectangle is on left side of other
		if ($leftP > $rightV || $leftV > $rightP)
			return 0.0;

		// If one rectangle is above other
		if ($topP > $bottomV || $topV > $bottomP)
			return 0.0;

		// Overlap area.
		$leftO = max($leftP, $leftV);
		$rightO = min($rightP, $rightV);
		$topO = max($topP, $topV);
		$bottomO = min($bottomP, $bottomV);

		// Get area of both rect areas
		$areaP = ($rightP - $leftP) * ($bottomP - $topP);
		$areaV = ($rightV - $leftV) * ($bottomV - $topV);
		$overlapArea = ($rightO - $leftO) * ($bottomO - $topO);

		// Calculate and return the overlay percent.
		return floatval($overlapArea / ($areaP + $areaV - $overlapArea));
	}

}

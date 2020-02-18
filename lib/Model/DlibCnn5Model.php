<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Model;

use OCP\IDBConnection;

use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\IModel;


class DlibCnn5Model implements IModel {

	/** Defines ID for default face model */
	const FACE_MODEL_ID = 1;

	/** Defines name for default face model */
	const FACE_MODEL_NAME = 'Default';

	/** Defines description for default face model */
	const FACE_MODEL_DESC = 'Main model, using dlib defaults: mmod_human_face_detector.dat, shape_predictor_5_face_landmarks.dat and dlib_face_recognition_resnet_model_v1.dat';

	/*
	 * Model files.
	 */
	const MODEL_DETECTOR = 0;
	const MODEL_PREDICTOR = 1;
	const MODEL_RESNET = 2;

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
	 * DlibCnn5Model __construct.
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


	public function getModelId(): int {
		return self::FACE_MODEL_ID;
	}

	public function getModelName(): string {
		return self::FACE_MODEL_NAME;
	}

	public function getModelDescription(): string {
		return self::FACE_MODEL_DESC;
	}

	public function isInstalled(): bool {
		$requirements = new Requirements($this->modelService, self::FACE_MODEL_ID);
		return $requirements->modelFilesPresent();
	}

	public function meetDependencies(): bool {
		$model = $this->settingsService->getCurrentFaceModel();
		$requirements = new Requirements($this->modelService, $model);
		return extension_loaded('pdlib') && $requirements->modelFilesPresent();
	}

	public function install() {
		if ($this->isInstalled()) {
			return;
		}

		/* Still not installed but it is necessary to get the model folders */
		$this->modelService->useModelVersion(self::FACE_MODEL_ID);

		/* Download and install models */
		$detectorModelBz2 = $this->fileService->downloaldFile(self::FACE_MODEL_BZ2_URLS[self::MODEL_DETECTOR]);
		$this->fileService->bunzip2($detectorModelBz2, $this->modelService->getModelPath(self::FACE_MODEL_FILES[self::MODEL_DETECTOR]));

		$predictorModelBz2 = $this->fileService->downloaldFile(self::FACE_MODEL_BZ2_URLS[self::MODEL_PREDICTOR]);
		$this->fileService->bunzip2($predictorModelBz2, $this->modelService->getModelPath(self::FACE_MODEL_FILES[self::MODEL_PREDICTOR]));

		$resnetModelBz2 = $this->fileService->downloaldFile(self::FACE_MODEL_BZ2_URLS[self::MODEL_RESNET]);
		$this->fileService->bunzip2($resnetModelBz2, $this->modelService->getModelPath(self::FACE_MODEL_FILES[self::MODEL_RESNET]));

		/* Clean temporary files */
		$this->fileService->clean();

		// Insert on database and enable it
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('facerecog_models')
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', self::FACE_MODEL_ID);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		if ((int)$data[0] <= 0) {
			$query = $this->connection->getQueryBuilder();
			$query->insert('facerecog_models')
			->values([
				'id' => $query->createNamedParameter(self::FACE_MODEL_ID),
				'name' => $query->createNamedParameter(self::FACE_MODEL_NAME),
				'description' => $query->createNamedParameter(self::FACE_MODEL_DESC)
			])
			->execute();
		}
	}

	public function setDefault() {
		// Use default model, if it is not set already.
		if ($this->settingsService->getCurrentFaceModel() !== self::FACE_MODEL_ID) {
			$this->settingsService->setCurrentFaceModel(self::FACE_MODEL_ID);
		}
	}

	public function open() {
		$this->modelService->useModelVersion(self::FACE_MODEL_ID);

		$this->cfd = new \CnnFaceDetection($this->modelService->getModelPath(self::FACE_MODEL_FILES[self::MODEL_DETECTOR]));
		$this->fld = new \FaceLandmarkDetection($this->modelService->getModelPath(self::FACE_MODEL_FILES[self::MODEL_PREDICTOR]));
		$this->fr = new \FaceRecognition($this->modelService->getModelPath(self::FACE_MODEL_FILES[self::MODEL_RESNET]));
	}

	public function detectFaces(string $imagePath): array {
		return $this->cfd->detect($imagePath);
	}

	public function detectLandmarks(string $imagePath, array $rect): array {
		return $this->fld->detect($imagePath, $rect);
	}

	public function computeDescriptor(string $imagePath, array $landmarks): array {
		return $this->fr->computeDescriptor($imagePath, $landmarks);
	}

}

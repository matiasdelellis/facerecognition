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

namespace OCA\FaceRecognition\Model\DlibCnnModel;

use OCP\IDBConnection;

use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\Service\FileService;
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

	const FACE_MODEL_BZ2_URLS = array();
	const FACE_MODEL_FILES = array();

	const I_MODEL_DETECTOR = 0;
	const I_MODEL_PREDICTOR = 1;
	const I_MODEL_RESNET = 2;

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

	public function isInstalled(): bool {
		$requirements = new Requirements($this->modelService, $this->getId());
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
		$this->modelService->useModelVersion($this->getId());

		/* Download and install models */
		$detectorModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_DETECTOR]);
		$this->fileService->bunzip2($detectorModelBz2, $this->modelService->getModelPath(static::FACE_MODEL_FILES[self::I_MODEL_DETECTOR]));

		$predictorModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_PREDICTOR]);
		$this->fileService->bunzip2($predictorModelBz2, $this->modelService->getModelPath(static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));

		$resnetModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_RESNET]);
		$this->fileService->bunzip2($resnetModelBz2, $this->modelService->getModelPath(static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));

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

	public function setDefault() {
		// Use default model, if it is not set already.
		if ($this->settingsService->getCurrentFaceModel() !== $this->getId()) {
			$this->settingsService->setCurrentFaceModel($this->getId());
		}
	}

	public function open() {
		$this->modelService->useModelVersion($this->getId());

		$this->cfd = new \CnnFaceDetection($this->modelService->getModelPath(static::FACE_MODEL_FILES[self::I_MODEL_DETECTOR]));
		$this->fld = new \FaceLandmarkDetection($this->modelService->getModelPath(static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));
		$this->fr = new \FaceRecognition($this->modelService->getModelPath(static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));
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

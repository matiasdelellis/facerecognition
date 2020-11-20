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

use OCA\FaceRecognition\Helper\FaceRect;

use OCA\FaceRecognition\Model\IModel;

use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model;
use OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel;

class DlibCnnHogModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = 4;
	const FACE_MODEL_NAME = "DlibCnnHog5";
	const FACE_MODEL_DESC = "Extends the main model, doing a face validation with the Hog detector";
	const FACE_MODEL_DOC = "https://github.com/matiasdelellis/facerecognition/wiki/Models#model-4";

	/** @var IDBConnection */
	private $connection;

	/** @var DlibCnn5Model */
	private $dlibCnn5Model;

	/** @var DlibHogModel */
	private $dlibHogModel;

	/**
	 * DlibCnnHogModel __construct.
	 *
	 * @param IDBConnection $connection
	 * @param DlibCnn5Model $dlibCnn5Model
	 * @param DlibHogModel $dlibHogModel
	 */
	public function __construct(IDBConnection   $connection,
	                            DlibCnn5Model   $dlibCnn5Model,
	                            DlibHogModel    $dlibHogModel)
	{
		$this->connection       = $connection;
		$this->dlibCnn5Model    = $dlibCnn5Model;
		$this->dlibHogModel     = $dlibHogModel;
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
		if (!$this->dlibCnn5Model->isInstalled())
			return false;
		if (!$this->dlibHogModel->isInstalled())
			return false;
		return true;
	}

	public function meetDependencies(string &$error_message): bool {
		if (!$this->dlibCnn5Model->isInstalled()) {
			$error_message = "This Model depend on Model 1 and must install it.";
			return false;
		}
		if (!$this->dlibHogModel->isInstalled()) {
			$error_message = "This Model depend on Model 3 and must install it.";
			return false;
		}
		return true;
	}

	public function getMaximumArea(): int {
		return $this->dlibCnn5Model->getMaximumArea();
	}

	public function getPreferredMimeType(): string {
		return $this->dlibCnn5Model->getPreferredMimeType();
	}

	public function install() {
		if ($this->isInstalled()) {
			return;
		}

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
		$this->dlibCnn5Model->open();
	}

	public function detectFaces(string $imagePath, bool $compute = true): array {
		$detectedFaces = [];

		$cnnFaces = $this->dlibCnn5Model->detectFaces($imagePath);
		if (count($cnnFaces) === 0) {
			return $detectedFaces;
		}

		$hogFaces = $this->dlibHogModel->detectFaces($imagePath, false);

		foreach ($cnnFaces as $proposedFace) {
			$detectedFaces[] = $this->validateFace($proposedFace, $hogFaces);
		}

		return $detectedFaces;
	}

	private function validateFace($proposedFace, $validateFaces) {
		foreach ($validateFaces as $validateFace) {
			$overlayPercent = FaceRect::getOverlayPercent($proposedFace, $validateFace);
			/**
			 * The weak link in our default model is the landmark detector that
			 * can't align profile or rotate faces correctly.
			 *
			 * The Hog detector also fails and cannot detect these faces. So, we
			 * consider if Hog detector can detect it, to infer when the predictor
			 * will give good results.
			 *
			 * If Hog detects it (Overlay > 35%), we can assume that landmark
			 * detector will do it too. In this case, we consider the face valid,
			 * and just return it.
			 */
			if ($overlayPercent >= 0.35) {
				return $proposedFace;
			}
		}

		/**
		 * If Hog don't detect this face, they are probably in profile or rotated.
		 * These are bad to compare, so we lower the confidence, to avoid clustering.
		 */
		$confidence = $proposedFace['detection_confidence'];
		$proposedFace['detection_confidence'] = $confidence * 0.8;

		return $proposedFace;
	}

}

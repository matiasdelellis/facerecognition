<?php
/**
 * @copyright Copyright (c) 2020-2024, Matias De lellis <mati86dl@gmail.com>
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

use OCA\FaceRecognition\Helper\FaceRect;

use OCA\FaceRecognition\Model\IModel;

use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model;

class DlibCnnHogModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = 4;
	const FACE_MODEL_NAME = "DlibCnnHog5";
	const FACE_MODEL_DESC = "Extends the main model, doing a face validation with the Hog detector";
	const FACE_MODEL_DOC = "https://github.com/matiasdelellis/facerecognition/wiki/Models#model-4";

	/** @var DlibCnn5Model */
	private $dlibCnn5Model;

	/**
	 * DlibCnnHogModel __construct.
	 *
	 * @param DlibCnn5Model $dlibCnn5Model
	 */
	public function __construct(DlibCnn5Model   $dlibCnn5Model)
	{
		$this->dlibCnn5Model    = $dlibCnn5Model;
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
		return true;
	}

	public function meetDependencies(string &$error_message): bool {
		if (!$this->dlibCnn5Model->isInstalled()) {
			$error_message = "This Model depend on Model 1 and must install it.";
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

	/**
	 * @return void
	 */
	public function install() {
		// This model reuses models 1 and should not install anything.
	}

	/**
	 * @return void
	 */
	public function open() {
		$this->dlibCnn5Model->open();
	}

	public function detectFaces(string $imagePath, bool $compute = true): array {
		$detectedFaces = [];

		$cnnFaces = $this->dlibCnn5Model->detectFaces($imagePath);
		if (count($cnnFaces) === 0) {
			return $detectedFaces;
		}

		$hogFaces = dlib_face_detection($imagePath);

		foreach ($cnnFaces as $proposedFace) {
			$detectedFaces[] = $this->validateFace($proposedFace, $hogFaces);
		}

		return $detectedFaces;
	}

	public function compute(string $imagePath, array $face): array {
		return $this->dlibCnn5Model->compute($imagePath, $face);
	}

	private function validateFace($proposedFace, array $validateFaces) {
		foreach ($validateFaces as $validateFace) {
			$overlapPercent = FaceRect::overlapPercent($proposedFace, $validateFace);
			/**
			 * The weak link in our default model is the landmark detector that
			 * can't align profile or rotate faces correctly.
			 *
			 * The Hog detector also fails and cannot detect these faces. So, we
			 * consider if Hog detector can detect it, to infer when the predictor
			 * will give good results.
			 *
			 * If Hog detects it (Overlap > 35%), we can assume that landmark
			 * detector will do it too. In this case, we consider the face valid,
			 * and just return it.
			 */
			if ($overlapPercent >= 0.35) {
				return $proposedFace;
			}
		}

		/**
		 * If Hog don't detect this face, they are probably in profile or rotated.
		 * These are bad to compare, so we lower the confidence, to avoid clustering.
		 */
		$confidence = $proposedFace['detection_confidence'];
		$proposedFace['detection_confidence'] = $confidence * 0.6;

		return $proposedFace;
	}

}

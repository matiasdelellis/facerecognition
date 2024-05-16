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

namespace OCA\FaceRecognition\Model\DlibTaguchiHogModel;

use OCA\FaceRecognition\Helper\FaceRect;

use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnnModel;
use OCA\FaceRecognition\Model\IModel;

class DlibTaguchiHogModel extends DlibCnnModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = 6;
	const FACE_MODEL_NAME = "DlibTaguchiHog";
	const FACE_MODEL_DESC = "Extends the Taguchi model, doing a face validation with the Hog detector";
	const FACE_MODEL_DOC = "https://github.com/matiasdelellis/facerecognition/wiki/Models#model-6";

	/** Relationship between image size and memory consumed */
	const MEMORY_AREA_RELATIONSHIP = 1 * 1024;
	const MINIMUM_MEMORY_REQUIREMENTS = 1 * 1024 * 1024 * 1024;

	/*
	 * Model files.
	 */
	const FACE_MODEL_FILES = [
		'detector' => [
			'url' => 'https://github.com/davisking/dlib-models/raw/94cdb1e40b1c29c0bfcaf7355614bfe6da19460e/mmod_human_face_detector.dat.bz2',
			'filename' => 'mmod_human_face_detector.dat'
		],
		'predictor' => [
			'url' => 'https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2',
			'filename' => 'shape_predictor_5_face_landmarks.dat',
		],
		'resnet' => [
			'url' => 'https://github.com/TaguchiModels/dlibModels/raw/main/taguchi_face_recognition_resnet_model_v1.7z',
			'filename' => 'taguchi_face_recognition_resnet_model_v1.dat'
		]
	];

	public function detectFaces(string $imagePath, bool $compute = true): array {
		$detectedFaces = [];

		$cnnFaces = parent::detectFaces($imagePath);
		if (count($cnnFaces) === 0) {
			return $detectedFaces;
		}

		$hogFaces = dlib_face_detection($imagePath);

		foreach ($cnnFaces as $proposedFace) {
			$detectedFaces[] = $this->validateFace($proposedFace, $hogFaces);
		}

		return $detectedFaces;
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

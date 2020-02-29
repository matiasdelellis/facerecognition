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

namespace OCA\FaceRecognition\Model\DlibCnnModel;

use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnnModel;
use OCA\FaceRecognition\Model\IModel;

class DlibCnn68Model extends DlibCnnModel implements IModel {

	/** Defines ID for default face model */
	const FACE_MODEL_ID = 2;

	/** Defines name for default face model */
	const FACE_MODEL_NAME = 'DlibCnn68';

	/** Defines description for default face model */
	const FACE_MODEL_DESC = 'Alternative default model, using dlib: mmod_human_face_detector.dat, shape_predictor_68_face_landmarks.dat and dlib_face_recognition_resnet_model_v1.dat';

	/** Relationship between image size and memory consumed */
	const MEMORY_AREA_RELATIONSHIP = 4 * 1024;

	/*
	 * Model files.
	 */
	const FACE_MODEL_BZ2_URLS = [
		'https://github.com/davisking/dlib-models/raw/94cdb1e40b1c29c0bfcaf7355614bfe6da19460e/mmod_human_face_detector.dat.bz2',
		'https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_68_face_landmarks.dat.bz2',
		'https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2'
	];

	const FACE_MODEL_FILES = [
		'mmod_human_face_detector.dat',
		'shape_predictor_68_face_landmarks.dat',
		'dlib_face_recognition_resnet_model_v1.dat'
	];

}

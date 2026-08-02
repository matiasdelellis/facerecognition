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

namespace OCA\FaceRecognition\Model;

/**
 * Interface IModel
 */
interface IModel {

	/**
	 * @return int model id
	 */
	public function getId(): int;

	/**
	 * @return string model name
	 */
	public function getName(): string;

	/**
	 * @return string model description
	 */
	public function getDescription(): string;

	/**
	 * @return string model documentation link
	 */
	public function getDocumentation(): string;

	/**
	 * @return bool if model is installed
	 */
	public function isInstalled(): bool;

	/**
	 * @return bool if meet dependencies to run the model
	 */
	public function meetDependencies(string &$error_message): bool;

	/**
	 * Obtain the maximum image area recommended by the model.
	 */
	public function getMaximumArea(): int;

	/**
	 * Identifies how the descriptors are computed: the face alignment (which
	 * landmark predictor) and the network that turns the aligned face into the
	 * descriptor. Two models only produce comparable descriptors when this
	 * method returns the same value, i.e. when a face analyzed with one of them
	 * could have been analyzed with the other. The HOG model aligns with the
	 * 5-point predictor and uses the standard network, like models 1 and 4, so
	 * they agree with each other; model 2 aligns with the 68-point predictor,
	 * and model 6 uses a different network, so neither agrees with the HOG one.
	 */
	public function getDescriptorType(): string;

	/**
	 * Get the Mime Type preferred by the model
	 *
	 * @return string mimetype
	 */
	public function getPreferredMimeType(): string;

	/**
	 * Install the model.
	 */
	public function install();

	/**
	 * Open the model
	 */
	public function open();

	/**
	 * Detect faces on image.
	 *
	 * @param string $imagePath Image path to analyze
	 * @param bool $compute (optional) variable that indicates if must obtain the landmarks and the descriptor.
	 * @return array where values are assoc arrays with "top", "bottom", "left", "right" of each face rect,
	 * "detection_confidence", and optionally the "landmarks", and "descriptor" according to the compute parameter.
	 */
	public function detectFaces(string $imagePath, bool $compute = true): array;

	/**
	 * Detect landmarks and compute descriptor of an face over an image.
	 *
	 * @param string $imagePath Image path to analyze
	 * @param array $face with "top", "bottom", "left", "right" of an face in that imagePath
	 * @return array as and copy of that face adding the values of the "landmarks" and the "descriptor"
	 */
	public function compute(string $imagePath, array $face): array;

}

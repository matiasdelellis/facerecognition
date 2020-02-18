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
	public function getModelId(): int;

	/**
	 * @return string model name
	 */
	public function getModelName(): string;

	/**
	 * @return string model description
	 */
	public function getModelDescription(): string;

	/**
	 * @return bool if model is installed
	 */
	public function isInstalled(): bool;

	/**
	 * @return bool if meet dependencies to run the model
	 */
	public function meetDependencies(): bool;

	/**
	 * Install the model.
	 */
	public function install();

	/**
	 * Set as default model to use.
	 */
	public function setDefault();

	/**
	 * Open the model
	 */
	public function open();

	/**
	 * Detect faces on image.
	 *
	 * @param string $imagePath Image path to analyze
	 * @return array where values are assoc arrays with "top", "bottom", "left" and "right" values
	 */
	public function detectFaces(string $imagePath): array;

	/**
	 * Detect Landmarks of face within the rectangle of image
	 *
	 * @param string $imagePath Image path to analyze
	 * @param array $rect with "top", "bottom", "left" and "right" values
	 * @return array where values are landmarks of face in image that will depend specifically on the model
	 */
	public function detectLandmarks(string $imagePath, array $rect): array;

	/**
	 * Get a descriptor of the face found
	 *
	 * @param string $imagePath Image path to analyze
	 * @param array $landmarks of face in image that will depend specifically on the model
	 * @return array where values are an descriptor that will depend specifically on the model
	 */
	public function computeDescriptor(string $imagePath, array $landmarks): array;

}

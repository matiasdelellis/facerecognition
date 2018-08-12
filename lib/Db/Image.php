<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Db;

use JsonSerializable;

use OCP\AppFramework\Db\Entity;

/**
 * Image represent one image file for one user.
 */
class Image extends Entity implements JsonSerializable {

	/**
	 * User this image belongs to.
	 *
	 * @var string
	 * */
	protected $user;

	/**
	 * File that this image refer to.
	 *
	 * @var integer
	 */
	protected $file;

	/**
	 * Face model that processed this image.
	 *
	 * @var integer
	 */
	protected $model;

	/**
	 * Whether this image is processed or not. Needed because image doesn't have to have any faces on it,
	 * yet we still need to know if it is being processed or not.
	 *
	 * @var bool
	 */
	protected $is_processed;

	/**
	 * Description of error that happened during image processing.
	 * If it exist, image processing should be skipped even if $is_processed is false.
	 *
	 * @var string|null
	 */
	protected $error;

	/**
	 * Timestamp when this image was last processed.
	 *
	 * @var timestamp|null
	*/
	protected $last_processed_time;

	/**
	 * Duration (in ms) it took to completely process this image. Should serve as a way to give estimates to user.
	 *
	 * @var integer|null
	*/
	protected $processing_duration;

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user' => $this->user,
			'file' => $this->file,
			'model' => $this->model,
			'is_processed' => $this->is_processed,
			'error' => $this->error,
			'last_processed_time' => $this->last_processed_time,
			'processing_duration' => $this->processing_duration
		];
	}
}

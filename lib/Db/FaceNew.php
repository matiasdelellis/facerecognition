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
 * Face represents one found face from one image.
 *
 * todo: currently named facev2 as it should not interfere with existing face. Once we switch, it should be renamed to "Face".
 */
class FaceNew extends Entity implements JsonSerializable {

	/**
	 * Image from this face originated from.
	 *
	 * @var integer
	 * */
	protected $image;

	/**
	 * Person (cluster) that this face belongs to
	 *
	 * @var integer|null
	 * */
	public $person;

	/**
	 * Left border of bounding rectangle for this face
	 *
	 * @var integer
	 * */
	protected $left;

	/**
	 * Right border of bounding rectangle for this face
	 *
	 * @var integer
	 * */
	protected $right;

	/**
	 * Top border of bounding rectangle for this face
	 *
	 * @var integer
	 * */
	protected $top;

	/**
	 * Bottom border of bounding rectangle for this face
	 *
	 * @var integer
	 * */
	protected $bottom;

	/**
	 * 128D face descriptor for this face.
	 *
	 * @var json_array
	 * */
	public $descriptor;

	/**
	 * Time when this face was found
	 *
	 * @var timestamp
	 * */
	protected $creation_time;

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'image' => $this->image,
			'person' => $this->person,
			'left' => $this->left,
			'right' => $this->right,
			'top' => $this->top,
			'bottom' => $this->bottom,
			'descriptor' => $this->descriptor,
			'creation_time' => $this->creation_time
		];
	}

	public function setDescriptor($descriptor) {
		$this->descriptor = json_decode($descriptor);
	}
}
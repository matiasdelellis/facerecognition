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
 * @method int getImage()
 * @method int getLeft()
 * @method int getRight()
 * @method int getTop()
 * @method int getBottom()
 * @method float getConfidence()
 * @method void setImage(int $image)
 * @method void setPerson(int $person)
 * @method void setLeft(int $left)
 * @method void setRight(int $right)
 * @method void setTop(int $top)
 * @method void setBottom(int $bottom)
 * @method void setConfidence(float $confidence)
 */
class Face extends Entity implements JsonSerializable {

	/**
	 * Image from this face originated from.
	 *
	 * @var int
	 * */
	public $image;

	/**
	 * Person (cluster) that this face belongs to
	 *
	 * @var int|null
	 * */
	public $person;

	/**
	 * Left border of bounding rectangle for this face
	 *
	 * @var int
	 * */
	public $left;

	/**
	 * Right border of bounding rectangle for this face
	 *
	 * @var int
	 * */
	public $right;

	/**
	 * Top border of bounding rectangle for this face
	 *
	 * @var int
	 * */
	public $top;

	/**
	 * Bottom border of bounding rectangle for this face
	 *
	 * @var int
	 * */
	public $bottom;

	/**
	 * Confidence of face detection obtained from the model
	 *
	 * @var float
	 * */
	public $confidence;

	/**
	 * landmarks for this face.
	 *
	 * @var array
	 * */
	public $landmarks;

	/**
	 * 128D face descriptor for this face.
	 *
	 * @var array
	 * */
	public $descriptor;

	/**
	 * Time when this face was found
	 *
	 * @var \DateTime
	 * */
	public $creationTime;

	/**
	 * Factory method to create Face from face structure that is returned as output of the model.
	 *
	 * @param int $image Image Id
	 * @param array $faceFromModel Face obtained from DNN model
	 * @return Face Created face
	 */
	public static function fromModel(int $image, array $faceFromModel): Face {
		$face = new Face();
		$face->setImage($image);
		$face->setPerson(null);
		$face->setLeft(max($faceFromModel["left"], 0));
		$face->setRight($faceFromModel["right"]);
		$face->setTop(max($faceFromModel["top"], 0));
		$face->setBottom($faceFromModel["bottom"]);
		$face->setConfidence($faceFromModel["detection_confidence"]);
		$face->setLandmarks("[]");
		$face->setDescriptor("[]");
		$face->setCreationTime(new \DateTime());
		return $face;
	}

	/**
	 * Helper method, to normalize face sizes back to original dimensions, based on ratio
	 *
	 * @param float $ratio Ratio of image resize
	 */
	public function normalizeSize($ratio) {
		$this->left =   intval(round($this->left * $ratio));
		$this->right =  intval(round($this->right * $ratio));
		$this->top =    intval(round($this->top * $ratio));
		$this->bottom = intval(round($this->bottom * $ratio));
	}

	/**
	 * Gets face width
	 *
	 * @return int Face width
	 */
	public function width(): int {
		return $this->right - $this->left;
	}

	/**
	 * Gets face height
	 *
	 * @return int Face height
	 */
	public function height(): int {
		return $this->bottom - $this->top;
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'image' => $this->image,
			'person' => $this->person,
			'left' => $this->left,
			'right' => $this->right,
			'top' => $this->top,
			'bottom' => $this->bottom,
			'confidence' => $this->confidence,
			'landmarks' => $this->landmarks,
			'descriptor' => $this->descriptor,
			'creation_time' => $this->creationTime
		];
	}

	public function getLandmarks(): string {
		return json_encode($this->landmarks);
	}

	public function setLandmarks($landmarks) {
		$this->landmarks = json_decode($landmarks);
		$this->markFieldUpdated('landmarks');
	}

	public function getDescriptor(): string {
		return json_encode($this->descriptor);
	}

	public function setDescriptor($descriptor) {
		$this->descriptor = json_decode($descriptor);
		$this->markFieldUpdated('descriptor');
	}

	public function setCreationTime($creationTime) {
		if (is_a($creationTime, 'DateTime')) {
			$this->creationTime = $creationTime;
		} else {
			$this->creationTime = new \DateTime($creationTime);
		}
		$this->markFieldUpdated('creationTime');
	}
}
<?php

/**
 * @copyright Copyright (c) 2017-2021 Matias De lellis <mati86dl@gmail.com>
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
 * @method int getPerson()
 * @method int getX()
 * @method int getY()
 * @method int getWidth()
 * @method int getHeight()
 * @method float getConfidence()
 * @method void setImage(int $image)
 * @method void setPerson(int $person)
 * @method void setX(int $x)
 * @method void setY(int $y)
 * @method void setWidth(int $width)
 * @method void setHeight(int $height)
 * @method void setConfidence(float $confidence)
 */
class Face extends Entity implements JsonSerializable
{

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
	public $x;

	/**
	 * Top border of bounding rectangle for this face
	 *
	 * @var int
	 * */
	public $y;

	/**
	 * Width of this face from the left border
	 *
	 * @var int
	 * */
	public $width;

	/**
	 * Height of this face from top border
	 *
	 * @var int
	 * */
	public $height;

	/**
	 * Confidence of face detection obtained from the model
	 *
	 * @var float
	 * */
	public $confidence;

	/**
	 * If it can be grouped according to the configurations
	 *
	 * @var bool
	 **/
	public $isGroupable;

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

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('image', 'integer');
		$this->addType('person', 'integer');
		$this->addType('isGroupable', 'bool');
		$this->addType('descriptor', 'json');
		$this->addType('creationTime', 'datetime');
	}

	/**
	 * Factory method to create Face from face structure that is returned as output of the model.
	 *
	 * @param int $image Image Id
	 * @param array $faceFromModel Face obtained from DNN model
	 * @return Face Created face
	 */
	public static function fromModel(int $imageId, array $faceFromModel): Face {
		$face = new Face();
		$face->image      = $imageId;
		$face->person     = null;
		$face->x          = $faceFromModel['left'];
		$face->y          = $faceFromModel['top'];
		$face->width      = $faceFromModel['right'] - $faceFromModel['left'];
		$face->height     = $faceFromModel['bottom'] - $faceFromModel['top'];
		$face->confidence = $faceFromModel['detection_confidence'];
		$face->landmarks  = isset($faceFromModel['landmarks']) ? $faceFromModel['landmarks'] : [];
		$face->descriptor = isset($faceFromModel['descriptor']) ? $faceFromModel['descriptor'] : [];
		$face->setCreationTime(new \DateTime());
		return $face;
	}

	public function jsonSerialize(): mixed {
		return [
			'id' => $this->id,
			'image' => $this->image,
			'person' => $this->person,
			'x' => $this->x,
			'y' => $this->y,
			'width' => $this->width,
			'height' => $this->height,
			'confidence' => $this->confidence,
			'is_groupable' => $this->isGroupable,
			'landmarks' => $this->landmarks,
			'descriptor' => $this->descriptor,
			'creation_time' => $this->creationTime
		];
	}

	public function getLandmarks(): string {
		return json_encode($this->landmarks);
	}

	public function setLandmarks($landmarks): void {
		$this->landmarks = json_decode($landmarks);
		$this->markFieldUpdated('landmarks');
	}

	public function getDescriptor(): string {
		return json_encode($this->descriptor);
	}

	public function setDescriptor($descriptor): void {
		$this->descriptor = json_decode($descriptor);
		$this->markFieldUpdated('descriptor');
	}

	public function setCreationTime($creationTime): void {
		if (is_a($creationTime, 'DateTime')) {
			$this->creationTime = $creationTime;
		} else {
			$this->creationTime = new \DateTime($creationTime);
		}
		$this->markFieldUpdated('creationTime');
	}
}

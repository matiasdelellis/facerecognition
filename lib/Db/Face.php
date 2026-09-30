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
 * @method int getCluster()
 * @method int getX()
 * @method int getY()
 * @method int getWidth()
 * @method int getHeight()
 * @method float getConfidence()
 * @method bool getIsGroupable()
 * @method bool getIsManual()
 * @method string|null getManualState()
 * @method bool|null getBoxAdjusted()
 * @method void setImage(int $image)
 * @method void setCluster(int $cluster)
 * @method void setX(int $x)
 * @method void setY(int $y)
 * @method void setWidth(int $width)
 * @method void setHeight(int $height)
 * @method void setConfidence(float $confidence)
 * @method void setIsGroupable(bool $isGroupable)
 * @method void setIsManual(bool $isManual)
 * @method void setManualState(?string $manualState)
 * @method void setBoxAdjusted(bool $boxAdjusted)
 */
class Face extends Entity implements JsonSerializable {

	/**
	 * States of the search for a descriptor in the region of a face the user
	 * marked by hand. They say how that search ended, and nothing about the
	 * clustering: whether a face takes part in it is derived from the face
	 * itself, every time it is asked.
	 */

	/** Marked, and the search did not run yet */
	public const MANUAL_STATE_PENDING = 'pending';

	/** The search found a face in the marked region and took its descriptor */
	public const MANUAL_STATE_FOUND = 'found';

	/** The search ran and there was no face in the marked region */
	public const MANUAL_STATE_NO_FACE = 'no_face';

	/** The analysis of the whole photo found the same face by itself later */
	public const MANUAL_STATE_CONFIRMED = 'confirmed';

	/**
	 * Image from this face originated from.
	 *
	 * @var int
	 * */
	public $image;

	/**
	 * Cluster of faces that look alike this one belongs to. Who that cluster
	 * is, if the user already said, is the person of the cluster.
	 *
	 * @var int|null
	 * */
	public $cluster;

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
	 * Whether this face was added manually by the user (vs. detected by the model).
	 *
	 * @var bool
	 **/
	public $isManual;

	/**
	 * How the search for a descriptor ended, for a face the user marked by
	 * hand: one of the MANUAL_STATE_* values. Null for every face that is not
	 * such a marking, which are the ones the analysis found and the ones the
	 * user only moved to another person.
	 *
	 * @var string|null
	 **/
	public $manualState;

	/**
	 * Whether the search for a descriptor put the box of a marking somewhere
	 * else than where the user drew it.
	 *
	 * @var bool|null
	 **/
	public $boxAdjusted;

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
		$this->addType('cluster', 'integer');
		$this->addType('isGroupable', 'boolean');
		$this->addType('isManual', 'boolean');
		$this->addType('manualState', 'string');
		$this->addType('boxAdjusted', 'boolean');
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
		$face->image       = $imageId;
		$face->cluster     = null;
		$face->isGroupable = true;
		$face->isManual    = false;
		$face->x           = $faceFromModel['left'];
		$face->y           = $faceFromModel['top'];
		$face->width       = $faceFromModel['right'] - $faceFromModel['left'];
		$face->height      = $faceFromModel['bottom'] - $faceFromModel['top'];
		$face->confidence  = $faceFromModel['detection_confidence'];
		$face->landmarks   = isset($faceFromModel['landmarks']) ? $faceFromModel['landmarks'] : [];
		$face->descriptor  = isset($faceFromModel['descriptor']) ? $faceFromModel['descriptor'] : [];
		$face->setCreationTime(new \DateTime());
		return $face;
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'image' => $this->image,
			'cluster' => $this->cluster,
			'x' => $this->x,
			'y' => $this->y,
			'width' => $this->width,
			'height' => $this->height,
			'confidence' => $this->confidence,
			'is_groupable' => $this->isGroupable,
			'is_manual' => $this->isManual,
			'manual_state' => $this->manualState,
			'box_adjusted' => $this->boxAdjusted,
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
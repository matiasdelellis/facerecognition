<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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
 * A region of a photo the user asked to be searched for faces again.
 *
 * The analysis never sees a photo at its full size: it is scaled down to the
 * area the memory allows, and a small face can fall below what the detector
 * finds. A region is cut out of the original photo and scaled up instead, so
 * the same face reaches the detector several times bigger.
 *
 * The counters keep the result of the search of this very region: from the
 * faces of the photo it could not be told any more which ones came from which
 * region, nor which ones were not even created.
 *
 * @method int getImage()
 * @method int getX()
 * @method int getY()
 * @method int getWidth()
 * @method int getHeight()
 * @method string getState()
 * @method int getFoundCount()
 * @method int getTooSmallCount()
 * @method int getLowConfidenceCount()
 * @method string|null getError()
 * @method \DateTime getCreationTime()
 * @method \DateTime|null getProcessedTime()
 * @method void setImage(int $image)
 * @method void setX(int $x)
 * @method void setY(int $y)
 * @method void setWidth(int $width)
 * @method void setHeight(int $height)
 * @method void setState(string $state)
 * @method void setFoundCount(int $foundCount)
 * @method void setTooSmallCount(int $tooSmallCount)
 * @method void setLowConfidenceCount(int $lowConfidenceCount)
 * @method void setError(?string $error)
 * @method void setCreationTime(\DateTime $creationTime)
 * @method void setProcessedTime(?\DateTime $processedTime)
 */
class ManualRegion extends Entity implements JsonSerializable {

	/** Waiting for the next run of the background job */
	public const STATE_PENDING = 'pending';

	/** Searched; the counters say what was found */
	public const STATE_DONE = 'done';

	/** Could not be searched; the error says why */
	public const STATE_FAILED = 'failed';

	/** @var int Image of the photo the region is on */
	protected $image;

	/** @var int Left border, in pixels of the original photo */
	protected $x;

	/** @var int Top border, in pixels of the original photo */
	protected $y;

	/** @var int */
	protected $width;

	/** @var int */
	protected $height;

	/** @var string One of the STATE_* values */
	protected $state;

	/** @var int Faces created from what the search found */
	protected $foundCount;

	/** @var int Of those, the ones smaller than the minimum face size */
	protected $tooSmallCount;

	/** @var int Of those, the ones below the minimum confidence */
	protected $lowConfidenceCount;

	/** @var string|null Why the search failed */
	protected $error;

	/** @var \DateTime */
	protected $creationTime;

	/** @var \DateTime|null */
	protected $processedTime;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('image', 'integer');
		$this->addType('x', 'integer');
		$this->addType('y', 'integer');
		$this->addType('width', 'integer');
		$this->addType('height', 'integer');
		$this->addType('state', 'string');
		$this->addType('foundCount', 'integer');
		$this->addType('tooSmallCount', 'integer');
		$this->addType('lowConfidenceCount', 'integer');
		$this->addType('error', 'string');
		$this->addType('creationTime', 'datetime');
		$this->addType('processedTime', 'datetime');
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'x' => $this->x,
			'y' => $this->y,
			'width' => $this->width,
			'height' => $this->height,
			'state' => $this->state,
			'foundCount' => $this->foundCount,
			'tooSmallCount' => $this->tooSmallCount,
			'lowConfidenceCount' => $this->lowConfidenceCount,
			'error' => $this->error,
		];
	}
}

<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018-2019 Branko Kokanovic <branko@kokanovic.org>
 * @copyright Copyright (c) 2018-2020 Matias De lellis <mti86dl@gmail.com>
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
namespace OCA\FaceRecognition\Helper;

use OCP\Image;
use OCP\ITempManager;
use OCA\FaceRecognition\Helper\Imaginary;

class TempImage extends Image {

	/** @var Imaginary */
	private $imaginary;

	/** @var string */
	private $imagePath;

	/** @var string */
	private $tempPath;

	/** @var string */
	private $preferredMimeType;

	/** @var int */
	private $maxImageArea;

	/** @var ITempManager */
	private $tempManager;

	/** @var int */
	private $minImageSide;

	/** @var float */
	private $ratio = -1.0;

	/** @var bool */
	private $skipped = false;

	public function __construct(string $imagePath,
	                            string $preferredMimeType,
	                            int    $maxImageArea,
	                            int    $minImageSide)
	{
		parent::__construct();

		$this->imagePath         = $imagePath;
		$this->preferredMimeType = $preferredMimeType;
		$this->maxImageArea      = $maxImageArea;
		$this->minImageSide      = $minImageSide;

		$this->tempManager       = \OC::$server->get(ITempManager::class);
		$this->imaginary         = new Imaginary();

		$this->prepareImage();
	}

	/**
	 * Get the path of temporary image
	 *
	 * @return string
	 */
	public function getTempPath(): string {
		return $this->tempPath;
	}

	/**
	 * Obtain the ratio of the temporary image against the original
	 *
	 * @return float
	 */
	public function getRatio(): float {
		return $this->ratio;
	}

	/** Return if image was skipped
	 *
	 * @return bool
	 */
	public function getSkipped(): bool {
		return $this->skipped;
	}

	/**
	 * Clean temporary files
	 */
	public function clean() {
		$this->tempManager->clean();
	}

	/**
	 * Obtain a temporary image according to the imposed restrictions.
	 *
	 */
	private function prepareImage() {
		if ($this->imaginary->isEnabled()) {
			$this->prepareWithImaginary();
		} else {
			$this->prepareLocally();
		}

		if ($this->skipped) {
			return;
		}

		$this->tempPath = $this->tempManager->getTemporaryFile();
		$this->save($this->tempPath, $this->preferredMimeType);
	}

	/**
	 * Prepare the temporary image using the Imaginary service, that does the
	 * resize in the server side.
	 */
	private function prepareWithImaginary() {
		$fileInfo = $this->imaginary->getInfo($this->imagePath);

		$widthOrig = $fileInfo['width'];
		$heightOrig = $fileInfo['height'];
		if ($this->isTooSmall($widthOrig, $heightOrig)) {
			return;
		}

		$scaleFactor = $this->getResizeRatio($widthOrig, $heightOrig);
		[$newWidth, $newHeight] = $this->getTargetSize($widthOrig, $heightOrig, $scaleFactor);

		$resizedResource = $this->imaginary->getResized($this->imagePath, $newWidth, $newHeight, $fileInfo['autorotate'], $this->preferredMimeType);
		$this->loadFromData($resizedResource);

		if (!$this->valid()) {
			throw new \RuntimeException("Imaginary image response is not valid.");
		}

		$this->ratio = 1 / $scaleFactor;
	}

	/**
	 * Prepare the temporary image locally, resizing it in memory.
	 */
	private function prepareLocally() {
		// The image is loaded into this instance, and the maximum area is
		// already imposed on the decoding, so that a format that only Imagick
		// can read does not have to be held whole in memory first.
		$sourceSize = ImageUtil::loadInto($this, $this->imagePath, true, $this->maxImageArea);
		if ($sourceSize === null) {
			throw new \RuntimeException("Local image is not valid, probably cannot be loaded");
		}

		// Everything is measured against the source image, and not against
		// what was loaded, since the decoding may have shrunk it already.
		[$widthOrig, $heightOrig] = $sourceSize;
		if ($this->isTooSmall($widthOrig, $heightOrig)) {
			return;
		}

		$this->ratio = $this->resizeOCImage($widthOrig, $heightOrig);
	}

	/**
	 * Marks the image as skipped when it is smaller than the minimum side.
	 *
	 * @return bool true when the image must be skipped
	 */
	private function isTooSmall(int $width, int $height): bool {
		if (($width < $this->minImageSide) || ($height < $this->minImageSide)) {
			$this->skipped = true;
			return true;
		}
		return false;
	}

	/**
	 * Size the image must have to reach the maximum image area, preserving
	 * the ratio.
	 *
	 * @return int[] width and height of the resized image
	 */
	private function getTargetSize(int $width, int $height, float $scaleFactor): array {
		return [
			intval(round($width * $scaleFactor)),
			intval(round($height * $scaleFactor)),
		];
	}

	/**
	 * Resizes the image to reach max image area, but preserving ratio.
	 *
	 * @param int $widthOrig width of the source image
	 * @param int $heightOrig height of the source image
	 * @return float Ratio of resize. 1 if there was no resize
	 */
	private function resizeOCImage(int $widthOrig, int $heightOrig): float {
		if (($widthOrig <= 0) || ($heightOrig <= 0)) {
			$message = "Image is having non-positive width or height, cannot continue";
			throw new \RuntimeException($message);
		}

		$scaleFactor = $this->getResizeRatio($widthOrig, $heightOrig);
		[$newWidth, $newHeight] = $this->getTargetSize($widthOrig, $heightOrig, $scaleFactor);

		// The decoding may have already downscaled it to about this same size,
		// in which case there is nothing left to resize.
		if ((imagesx($this->resource()) !== $newWidth) ||
		    (imagesy($this->resource()) !== $newHeight)) {
			$success = $this->preciseResize($newWidth, $newHeight);
			if ($success === false) {
				throw new \RuntimeException("Error during image resize");
			}
		}

		return 1 / $scaleFactor;
	}

	/**
	 * Ratio between the maximum image area and the original one, to rescale.
	 *
	 * @return float scale factor to apply to the image
	 */
	private function getResizeRatio($widthOrig, $heightOrig): float {
		$areaRatio = $this->maxImageArea / ($widthOrig * $heightOrig);
		return sqrt($areaRatio);
	}

}
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
use OCP\ILogger;
use OCP\ITempManager;

class TempImage extends Image {

	/** @var string */
	private $imagePath;

	/** @var string */
	private $preferredMimeType;

	/** @var int */
	private $maxImageArea;

	/** @var \OCP\ILogger */
	private $logger;

	/** @var ITempManager */
	private $tempManager;

	/** @var int */
	private $minImageSide;

	/** @var float */
	private $ratio = -1.0;

	/** @var bool */
	private $skippedDetection = false;

	/** @var array<Face> All found faces in image */
	private $faces;

	public function __construct(string       $imagePath,
	                            string       $preferredMimeType,
	                            int          $maxImageArea,
	                            int          $minImageSide,
	                            ILogger      $logger,
	                            ITempManager $tempManager)
	{
		parent::__construct(null, $logger, null);

		$this->imagePath         = $imagePath;
		$this->preferredMimeType = $preferredMimeType;
		$this->maxImageArea      = $maxImageArea;
		$this->minImageSide      = $minImageSide;
		$this->logger            = $logger;
		$this->tempManager       = $tempManager;
	}

	function __destruct() {
		$this->tempManager->clean();
	}

	/**
	 * Obtain a temporary image according to the imposed restrictions.
	 *
	 * @return string|null  path of resized image
	 */
	public function getTempImage(): ?string {
		$this->loadFromFile($this->imagePath);
		$this->fixOrientation();

		if (!$this->valid()) {
			throw new \RuntimeException("Image is not valid, probably cannot be loaded");
		}

		if ((imagesx($this->resource()) < $this->minImageSide) ||
		    (imagesy($this->resource()) < $this->minImageSide)) {
			$this->skippedDetection = true;
			return null;
		}

		$this->ratio = $this->resizeImage();

		$tempfile = $this->tempManager->getTemporaryFile();
		$this->save($tempfile, $this->preferredMimeType);

		return $tempFile;
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
		return $this->skippedDetection;
	}
	/**
	 * Gets all faces
	 *
	 * @return Face[] Array of faces
	 */
	public function getFaces(): array {
		return $this->faces;
	}

	/**
	 * @param array<Face> $faces Array of faces to set
	 */
	public function setFaces(array $faces) {
		$this->faces = $faces;
	}

	/**
	 * Resizes the image to reach max image area, but preserving ratio.
	 *
	 * @return float Ratio of resize. 1 if there was no resize
	 */
	private function resizeImage(): float {
		$widthOrig = imagesx($this->resource());
		$heightOrig = imagesy($this->resource());

		if (($widthOrig <= 0) || ($heightOrig <= 0)) {
			$message = "Image is having non-positive width or height, cannot continue";
			$this->logger->info($message);
			throw new \RuntimeException($message);
		}

		$areaRatio = $this->maxImageArea / ($widthOrig * $heightOrig);
		$scaleFactor = sqrt($areaRatio);

		$newWidth = intval(round($widthOrig * $scaleFactor));
		$newHeight = intval(round($heightOrig * $scaleFactor));

		$success = $image->preciseResize($newWidth, $newHeight);
		if ($success === false) {
			throw new \RuntimeException("Error during image resize");
		}

		return 1 / $scaleFactor;
	}

}
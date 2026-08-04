<?php
declare(strict_types=1);
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
namespace OCA\FaceRecognition\Helper;

use OCP\Image;

/**
 * Small helpers to open image files.
 */
class ImageUtil {

	/**
	 * Load an image from a local path into a new image instance.
	 *
	 * @param string $path local path of the image
	 * @param bool $fixOrientation whether to rotate it according to the EXIF data
	 * @param int|null $maxArea area to downscale the image to when it has to be
	 *                          decoded with Imagick, or null to keep it whole
	 * @return Image|null the loaded image, or null when it cannot be decoded
	 */
	public static function loadFromPath(string $path, bool $fixOrientation = true, ?int $maxArea = null): ?Image {
		$image = new Image();
		return self::loadInto($image, $path, $fixOrientation, $maxArea) !== null ? $image : null;
	}

	/**
	 * Load an image from a local path into an image instance already created.
	 *
	 * The image is loaded with the usual GD backend (through Nextcloud
	 * OCP\Image), and when that cannot decode the file (HEIC, TIFF or AVIF,
	 * for example) it falls back to the Imagick extension.
	 *
	 * It fills an instance given by the caller instead of returning a new one,
	 * so that a subclass of Image can be loaded directly, without having to
	 * adopt the GD resource that belongs to another instance.
	 *
	 * The size returned is the one of the source file, already oriented but
	 * before any downscale, so the caller can still relate what it gets to the
	 * original image even when $maxArea shrank it.
	 *
	 * @param Image $image instance to load the image into
	 * @param string $path local path of the image
	 * @param bool $fixOrientation whether to rotate it according to the EXIF data
	 * @param int|null $maxArea area to downscale the image to when it has to be
	 *                          decoded with Imagick, or null to keep it whole
	 * @return int[]|null width and height of the source image, or null when it
	 *                    cannot be decoded
	 */
	public static function loadInto(Image $image, string $path, bool $fixOrientation = true, ?int $maxArea = null): ?array {
		if ($image->loadFromFile($path) !== false && $image->valid()) {
			if ($fixOrientation) {
				$image->fixOrientation();
			}
			// GD decodes the file whole, so what is loaded is the source size.
			return [$image->width(), $image->height()];
		}

		$decoded = self::decodeWithImagick($path, $fixOrientation, $maxArea);
		if ($decoded === null) {
			return null;
		}

		// Imagick already oriented the image, and the PNG blob carries no EXIF
		// data, so there is nothing left to fix here.
		if (($image->loadFromData($decoded['data']) === false) || !$image->valid()) {
			return null;
		}

		return [$decoded['width'], $decoded['height']];
	}

	/**
	 * Decode an image with the Imagick extension into a PNG blob, that the GD
	 * backend is then able to read.
	 *
	 * @param string $path local path of the image
	 * @param bool $fixOrientation whether to rotate it according to the EXIF data
	 * @param int|null $maxArea area to downscale the image to, or null to keep
	 *                          it whole
	 * @return array{data: string, width: int, height: int}|null the PNG data and
	 *         the size it had before the downscale, or null when it cannot be
	 *         decoded
	 */
	private static function decodeWithImagick(string $path, bool $fixOrientation, ?int $maxArea): ?array {
		if (!extension_loaded('imagick')) {
			return null;
		}

		$imagick = null;
		try {
			$imagick = new \Imagick();
			$imagick->readImage($path);

			// Sequences, like a HEIC burst or an animated GIF, are decoded
			// whole, but only the first frame is of any use to us.
			$imagick->setFirstIterator();

			if ($fixOrientation) {
				$imagick->autoOrient();
			}

			$width = $imagick->getImageWidth();
			$height = $imagick->getImageHeight();

			// Downscale before handing the image over to GD. Otherwise the
			// bitmap would live three times at full resolution at the same
			// time (the Imagick image, the PNG blob and the GD image), and the
			// formats that need this path are precisely the big ones, like the
			// HEIC that a phone takes.
			$area = $width * $height;
			if (($maxArea !== null) && ($maxArea > 0) && ($area > $maxArea)) {
				$scale = sqrt($maxArea / $area);
				$imagick->thumbnailImage((int) max(1, round($width * $scale)),
				                         (int) max(1, round($height * $scale)));
			}

			$imagick->setImageFormat('png');

			return [
				'data'   => $imagick->getImageBlob(),
				'width'  => $width,
				'height' => $height,
			];
		} catch (\Exception $e) {
			return null;
		} finally {
			// Release the decoded bitmap as soon as the blob is extracted,
			// instead of waiting for the garbage collector to do it.
			if ($imagick !== null) {
				$imagick->clear();
			}
		}
	}

}

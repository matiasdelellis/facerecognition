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
	 * @param int|null $maxArea area to downscale the image to when it cannot be
	 *                          decoded with GD, or null to keep it whole
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
	 * for example) it falls back to the Imagick extension, and finally to the
	 * Imaginary service, that is the only one that reads these formats when
	 * Imagick is not built with the proper delegates.
	 *
	 * Imagick is tried first because it decodes locally, without having to
	 * upload the file to a service, and because for the formats that get here
	 * it orients the image just like Imaginary does. Imagick simply does not
	 * apply the EXIF orientation of a HEIC or an AVIF, the same as the service.
	 * Where the two do not agree, decodeWithImagick() steps aside.
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
	 * @param int|null $maxArea area to downscale the image to when it cannot be
	 *                          decoded with GD, or null to keep it whole
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

		$imaginary = new Imaginary();
		$hasImaginary = $imaginary->isEnabled();

		$decoded = self::decodeWithImagick($path, $fixOrientation, $maxArea, $hasImaginary);
		if (($decoded === null) && $hasImaginary) {
			$decoded = self::decodeWithImaginary($imaginary, $path, $fixOrientation, $maxArea);
		}
		if ($decoded === null) {
			return null;
		}

		// The image is already oriented by the backend that decoded it, and the
		// PNG blob carries no EXIF data, so there is nothing left to fix here.
		if (($image->loadFromData($decoded['data']) === false) || !$image->valid()) {
			return null;
		}

		return [$decoded['width'], $decoded['height']];
	}

	/**
	 * Decode an image with the Imaginary service into a PNG blob, that the GD
	 * backend is then able to read.
	 *
	 * It is the same service, and the same orientation criteria, that the
	 * analysis uses to obtain the temporary images, so what is loaded here is
	 * the image on which the faces were found.
	 *
	 * @param Imaginary $imaginary service to decode the image with
	 * @param string $path local path of the image
	 * @param bool $fixOrientation whether to rotate it according to the EXIF data
	 * @param int|null $maxArea area to downscale the image to, or null to keep
	 *                          it whole
	 * @return array{data: string, width: int, height: int}|null the PNG data and
	 *         the size it had before the downscale, or null when the service
	 *         cannot decode the image
	 */
	private static function decodeWithImaginary(Imaginary $imaginary, string $path, bool $fixOrientation, ?int $maxArea): ?array {
		try {
			$info = $imaginary->getInfo($path);

			// The size is the one the service reports, that is already rotated
			// when it is going to rotate the image itself.
			$width = (int) $info['width'];
			$height = (int) $info['height'];
			if (($width <= 0) || ($height <= 0)) {
				return null;
			}

			// The resize is done by the service, so the image never travels at
			// full resolution, and only the downscaled one is held in memory.
			$newWidth = $width;
			$newHeight = $height;
			$area = $width * $height;
			if (($maxArea !== null) && ($maxArea > 0) && ($area > $maxArea)) {
				$scale = sqrt($maxArea / $area);
				$newWidth = (int) max(1, round($width * $scale));
				$newHeight = (int) max(1, round($height * $scale));
			}

			$data = $imaginary->getResized($path, $newWidth, $newHeight,
			                               $fixOrientation && $info['autorotate'],
			                               'image/png');
			if (is_resource($data)) {
				$data = stream_get_contents($data);
			}
			if (!is_string($data) || ($data === '')) {
				return null;
			}

			return [
				'data'   => $data,
				'width'  => $width,
				'height' => $height,
			];
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Decode an image with the Imagick extension into a PNG blob, that the GD
	 * backend is then able to read.
	 *
	 * @param string $path local path of the image
	 * @param bool $fixOrientation whether to rotate it according to the EXIF data
	 * @param int|null $maxArea area to downscale the image to, or null to keep
	 *                          it whole
	 * @param bool $hasImaginary whether the Imaginary service is configured, and
	 *                           can therefore take the images this one leaves
	 * @return array{data: string, width: int, height: int}|null the PNG data and
	 *         the size it had before the downscale, or null when it cannot be
	 *         decoded, or when Imaginary has to decode it instead
	 */
	private static function decodeWithImagick(string $path, bool $fixOrientation, ?int $maxArea, bool $hasImaginary = false): ?array {
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

			// The images were analyzed by Imaginary, that only applies the EXIF
			// orientation when it is greater than 4 (see Imaginary::getInfo).
			// Imagick applies all of them, so the ones in between would end up
			// oriented differently than when the faces were found on them, and
			// are left to the service. Imagick reports no orientation at all
			// for HEIC or AVIF, so those never take this exit.
			$orientation = $imagick->getImageOrientation();
			if ($hasImaginary && $fixOrientation && ($orientation > 1) && ($orientation <= 4)) {
				return null;
			}

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

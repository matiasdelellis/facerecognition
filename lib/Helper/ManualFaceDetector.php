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
namespace OCA\FaceRecognition\Helper;

use OCP\Files\File;
use OCP\ITempManager;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Service\FileService;

/**
 * Searches a region of a photo the user marked for faces: cuts the region, with
 * a margin, out of the original photo, scales it (see analysisArea()), runs
 * the detector on it, and gives the faces back in pixels of the original
 * photo.
 *
 * Why a crop helps even though the full photo was already analysed: the full
 * photo is downscaled to the model's maximum area before detection, so a small
 * face can fall below the detector's size threshold and be missed. The crop is
 * not downscaled as far, or even scaled up, so the same face is large enough
 * to be detected, and its descriptor is comparable to descriptors from
 * full-image detections (dlib aligns the face before computing it).
 *
 * Scaling up only helps the detector: the descriptor is computed from a chip
 * of 150 x 150 pixels, and a face of 40 pixels holds what 40 pixels hold, at
 * any scale. So the crop is scaled up no more than the detector needs, which
 * keeps the search fast and adds no interpolated detail the detector could
 * take for a face.
 *
 * A face drawn by hand and a region to search again both go through here, so
 * that orientation, mime type and the way back to the coordinates of the photo
 * are handled once. What is done with the faces found is up to each of them.
 */
class ManualFaceDetector {

	/**
	 * Prefix of every log line about the faces and regions the user marked,
	 * so that they can be told apart from the ones of the rest of the app.
	 */
	public const LOG_PREFIX = '[manual faces] ';

	/**
	 * The most a crop is scaled up. The detectors of dlib look for faces of
	 * about 80 pixels and more, so a face of the minimum size of 40 pixels is
	 * found at twice its size already; four times leaves room for a smaller
	 * drawn box. Anything beyond that is interpolation.
	 */
	public const MAX_UPSCALE = 4.0;

	/**
	 * Side a marked face is scaled up to, when its size is known: well above
	 * what the detector needs, and above the 150 pixels of the chip the
	 * descriptor is computed from.
	 */
	public const TARGET_FACE_SIDE = 200;

	/** @var FileService */
	private $fileService;

	/** @var ITempManager */
	private $tempManager;

	public function __construct(FileService $fileService, ITempManager $tempManager) {
		$this->fileService = $fileService;
		$this->tempManager = $tempManager;
	}

	/**
	 * The faces the model finds in a region of a photo, with the margin given
	 * around it, in pixels of the original photo. The region is in the same
	 * pixels, in the frame the photo is shown in, after its orientation.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 * @param int|null $faceSide expected side of the face, in pixels of the photo, if the region is one face
	 *
	 * @return array<int, array{x: int, y: int, width: int, height: int, confidence: float, landmarks: array, descriptor: array}>
	 *
	 * @throws \RuntimeException if the photo cannot be read, or the region is not on it
	 */
	public function detect(IModel $model, string $userId, int $fileId, array $rect, int $marginX, int $marginY, ?int $faceSide = null): array {
		// FileService keeps the user of the last setupFS() and prefers it to
		// the one it is given, and the tasks before this one set up each user
		// in turn: without this, the file would be looked for in the folder of
		// whichever user came last, and not be found.
		$this->fileService->setupFS($userId);
		$node = $this->fileService->getFileById($fileId, $userId);
		if (!($node instanceof File)) {
			throw new \RuntimeException('the file ' . $fileId . ' is not available');
		}

		$localPath = $this->fileService->getLocalFile($node);
		if ($localPath === null) {
			throw new \RuntimeException('the file ' . $fileId . ' cannot be read');
		}

		$crop = $this->cropRegion($localPath, $model->getPreferredMimeType(), $rect, $marginX, $marginY);

		// Reuse TempImage only for the scale and mime conversion. It scales to
		// exactly the area it is given, so the area decides the factor.
		// minImageSide is 1 on purpose: a face crop is meant to be small, so it
		// must not be skipped for being "too small".
		$tempImage = new TempImage(
			$crop['path'],
			$model->getPreferredMimeType(),
			self::analysisArea($crop['width'], $crop['height'], $model->getMaximumArea(), $faceSide),
			1
		);
		try {
			$rawFaces = $model->detectFaces($tempImage->getTempPath());
			$ratio = $tempImage->getRatio();
		} finally {
			$tempImage->clean();
		}

		$faces = [];
		foreach ($rawFaces as $rawFace) {
			$faces[] = self::toOriginal($rawFace, $ratio, $crop['offsetX'], $crop['offsetY']);
		}
		return $faces;
	}

	/**
	 * The area a crop is scaled to before the detector runs on it. TempImage
	 * scales to exactly this area, so it sets the factor: never above the
	 * maximum area of the model, which only ever shrinks a large region, and
	 * never up by more than MAX_UPSCALE. When the region is a single face of
	 * a known side, it is scaled up only until that face reaches
	 * TARGET_FACE_SIDE, and a face already that big is not scaled up at all.
	 */
	public static function analysisArea(int $cropWidth, int $cropHeight, int $maxArea, ?int $faceSide = null): int {
		$upscale = self::MAX_UPSCALE;
		if (!is_null($faceSide) && $faceSide > 0) {
			$upscale = min($upscale, max(1.0, self::TARGET_FACE_SIDE / $faceSide));
		}

		$area = (int) floor($cropWidth * $cropHeight * $upscale * $upscale);
		return max(1, min($maxArea, $area));
	}

	/**
	 * Removes the temporary files of the last search: the crop, and the copy
	 * of a file that is not on local storage.
	 */
	public function clean(): void {
		$this->tempManager->clean();
		$this->fileService->clean();
	}

	/**
	 * The rectangle to cut out of a photo for a region: the region with the
	 * margin on every side, kept inside the photo. Null if nothing of the
	 * region is on the photo.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array{x: int, y: int, width: int, height: int}|null
	 */
	public static function cropRect(int $imageWidth, int $imageHeight, array $rect, int $marginX, int $marginY): ?array {
		if ($imageWidth <= 0 || $imageHeight <= 0 || $rect['width'] <= 0 || $rect['height'] <= 0) {
			return null;
		}

		$left = max(0, $rect['x'] - $marginX);
		$top = max(0, $rect['y'] - $marginY);
		$right = min($imageWidth, $rect['x'] + $rect['width'] + $marginX);
		$bottom = min($imageHeight, $rect['y'] + $rect['height'] + $marginY);
		if ($right <= $left || $bottom <= $top) {
			return null;
		}

		return [
			'x' => $left,
			'y' => $top,
			'width' => $right - $left,
			'height' => $bottom - $top,
		];
	}

	/**
	 * Map a face detected on the crop back to original-image pixels. The
	 * detector runs on the (scaled) crop, so coordinates are scaled by the
	 * TempImage ratio and shifted by the crop offset, mirroring the
	 * normalization done for full-image detections. The landmarks go the same
	 * way, so that a face found here carries them like one of the analysis.
	 *
	 * @param array<string, mixed> $rawFace detection with left/top/right/bottom
	 *
	 * @return array{x: int, y: int, width: int, height: int, confidence: float, landmarks: array, descriptor: array}
	 */
	public static function toOriginal(array $rawFace, float $ratio, int $offsetX, int $offsetY): array {
		$left   = (int) round(((int) $rawFace['left'])   * $ratio) + $offsetX;
		$top    = (int) round(((int) $rawFace['top'])    * $ratio) + $offsetY;
		$right  = (int) round(((int) $rawFace['right'])  * $ratio) + $offsetX;
		$bottom = (int) round(((int) $rawFace['bottom']) * $ratio) + $offsetY;

		$landmarks = [];
		foreach ($rawFace['landmarks'] ?? [] as $landmark) {
			$landmarks[] = [
				'x' => (int) round($landmark['x'] * $ratio) + $offsetX,
				'y' => (int) round($landmark['y'] * $ratio) + $offsetY,
			];
		}

		return [
			'x'          => $left,
			'y'          => $top,
			'width'      => max(1, $right - $left),
			'height'     => max(1, $bottom - $top),
			'confidence' => (float) ($rawFace['detection_confidence'] ?? 0.0),
			'landmarks'  => $landmarks,
			'descriptor' => $rawFace['descriptor'] ?? [],
		];
	}

	/**
	 * Why the file of a photo cannot be read at all, or null if it can. The
	 * loader would only say that it cannot load it; this tells the admin what
	 * to look at. The path itself is left out, since the reason is also shown
	 * to the user with the region.
	 */
	public static function unreadableReason(string $localPath): ?string {
		if (!file_exists($localPath)) {
			return 'the file of the photo is missing on the storage';
		}
		if (!is_readable($localPath)) {
			return 'the file of the photo is not readable for the web server, check its permissions';
		}
		return null;
	}

	/**
	 * A box as the edges FaceRect compares.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array{left: int, right: int, top: int, bottom: int}
	 */
	public static function edges(array $rect): array {
		return [
			'left' => (int) $rect['x'],
			'right' => (int) $rect['x'] + (int) $rect['width'],
			'top' => (int) $rect['y'],
			'bottom' => (int) $rect['y'] + (int) $rect['height'],
		];
	}

	/**
	 * Crop the region (plus the margin) from the original image and save it to
	 * a temporary file. Coordinates are original-image pixels in the oriented
	 * frame, so the image is orientation-fixed before cropping.
	 *
	 * The crop offset is returned alongside the path so detections made on the
	 * crop can be mapped back to original-image pixels, and its size so that
	 * the scale can be chosen for it.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $rect
	 *
	 * @return array{path: string, offsetX: int, offsetY: int, width: int, height: int}
	 *
	 * @throws \RuntimeException if the photo cannot be loaded, or the region is not on it
	 */
	private function cropRegion(string $localPath, string $mimeType, array $rect, int $marginX, int $marginY): array {
		// The same loader the analysis uses, so that a face marked on a HEIC or
		// an AVIF is cropped from the same image the model saw. No maximum area
		// is imposed here: the rectangle is in pixels of the original image, and
		// a downscale would put the crop somewhere else.
		$unreadable = self::unreadableReason($localPath);
		if (!is_null($unreadable)) {
			throw new \RuntimeException($unreadable);
		}
		$image = ImageUtil::loadFromPath($localPath, true, null);
		if (is_null($image)) {
			throw new \RuntimeException('the photo cannot be loaded, it may be damaged or of a format that cannot be read');
		}

		$crop = self::cropRect($image->width(), $image->height(), $rect, $marginX, $marginY);
		if (is_null($crop)) {
			throw new \RuntimeException('the region is not on the photo');
		}

		if ($image->crop($crop['x'], $crop['y'], $crop['width'], $crop['height']) === false) {
			throw new \RuntimeException('the region cannot be cut out of the photo');
		}

		$cropPath = $this->tempManager->getTemporaryFile();
		if ($cropPath === false || $image->save($cropPath, $mimeType) === false) {
			throw new \RuntimeException('the region cannot be saved for the search');
		}

		return [
			'path'    => $cropPath,
			'offsetX' => $crop['x'],
			'offsetY' => $crop['y'],
			'width'   => $crop['width'],
			'height'  => $crop['height'],
		];
	}
}

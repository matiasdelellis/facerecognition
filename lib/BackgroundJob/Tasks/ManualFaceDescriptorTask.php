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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCP\Image as OCP_Image;
use OCP\ITempManager;
use OCP\Files\File;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Helper\TempImage;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Task that gives a meaning to the "use for clustering" option of manually added
 * faces. A manual face carries no model descriptor, so it cannot participate in
 * clustering on its own. For each manual face the user flagged for clustering,
 * this task crops the marked region from the original photo and runs face
 * detection on just that crop.
 *
 * Why a crop helps even though the full photo was already analysed: the full
 * photo is downscaled to the model's maximum area before detection, so a small
 * face can fall below the detector's size threshold and be missed. The crop is
 * analysed at (near) full resolution, so the same face is large enough to be
 * detected, and its descriptor is comparable to descriptors from full-image
 * detections (dlib aligns the face before computing it).
 *
 * If no face can be detected in the marked region, the face is simply excluded
 * from clustering (is_groupable = false). It stays pinned to its person. No
 * fallback descriptor is fabricated, and a single bad region never aborts the
 * background job.
 */
class ManualFaceDescriptorTask extends FaceRecognitionBackgroundTask {

	/**
	 * Extra context kept around the user rectangle before detection, as a
	 * fraction of the rectangle size. Detectors work better with some margin.
	 */
	private const CROP_MARGIN = 0.4;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var FileService */
	private $fileService;

	/** @var SettingsService */
	private $settingsService;

	/** @var ModelManager */
	private $modelManager;

	/** @var ITempManager */
	private $tempManager;

	public function __construct(FaceMapper      $faceMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService,
	                            ModelManager    $modelManager,
	                            ITempManager    $tempManager)
	{
		parent::__construct();

		$this->faceMapper      = $faceMapper;
		$this->fileService     = $fileService;
		$this->settingsService = $settingsService;
		$this->modelManager    = $modelManager;
		$this->tempManager     = $tempManager;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Compute descriptors for manually added faces flagged for clustering";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		$model = $this->modelManager->getCurrentModel();
		if (is_null($model)) {
			$this->logInfo('No current model configured, skipping manual face descriptor extraction');
			return true;
		}

		$modelId = $this->settingsService->getCurrentFaceModel();

		$opened = false;
		foreach ($this->context->getEligibleUsers() as $userId) {
			$pending = $this->faceMapper->findManualFacesPendingDescriptor($userId, $modelId);
			if (count($pending) === 0) {
				continue;
			}

			// Only open the (expensive) model when there is actually work to do.
			if (!$opened) {
				$model->open();
				$opened = true;
			}

			$this->logInfo('Computing descriptors for ' . count($pending) . ' manual face(s) of user ' . $userId);
			foreach ($pending as $row) {
				$this->computeDescriptorForFace($model, $userId, $row);
				yield;
			}
		}

		return true;
	}

	/**
	 * Compute and store the descriptor for a single pending manual face, or
	 * exclude it from clustering if no face is found. Never throws.
	 *
	 * @param array<string, mixed> $row id, file, x, y, width, height
	 */
	private function computeDescriptorForFace(IModel $model, string $userId, array $row): void {
		$faceId = (int) $row['id'];
		$cropPath = null;
		try {
			$node = $this->fileService->getFileById((int) $row['file'], $userId);
			if (!($node instanceof File)) {
				$this->logInfo('Manual face ' . $faceId . ': source file unavailable, excluding it from clustering');
				$this->faceMapper->markManualFaceNotGroupable($faceId);
				return;
			}

			$localPath = $this->fileService->getLocalFile($node);
			if ($localPath === null) {
				$this->faceMapper->markManualFaceNotGroupable($faceId);
				return;
			}

			$cropPath = $this->cropRegion(
				$localPath,
				$model->getPreferredMimeType(),
				(int) $row['x'], (int) $row['y'], (int) $row['width'], (int) $row['height']
			);
			if ($cropPath === null) {
				$this->faceMapper->markManualFaceNotGroupable($faceId);
				return;
			}

			// Reuse TempImage only for the max-area downscale (memory safety) and
			// mime conversion. minImageSide is 1 on purpose: a face crop is meant
			// to be small, so it must not be skipped for being "too small".
			$tempImage = new TempImage(
				$cropPath,
				$model->getPreferredMimeType(),
				$model->getMaximumArea(),
				1
			);

			$rawFaces = $model->detectFaces($tempImage->getTempPath());
			$tempImage->clean();

			if (count($rawFaces) === 0) {
				$this->logInfo('Manual face ' . $faceId . ': no face detected in the marked region, excluding it from clustering');
				$this->faceMapper->markManualFaceNotGroupable($faceId);
				return;
			}

			$best = $this->pickLargestFace($rawFaces);
			if (empty($best['descriptor'])) {
				$this->faceMapper->markManualFaceNotGroupable($faceId);
				return;
			}

			$this->faceMapper->setManualFaceDescriptor($faceId, $best['descriptor']);
			$this->logInfo('Manual face ' . $faceId . ': descriptor computed, it will be used for clustering');
		} catch (\Exception $e) {
			// Robustness: a single unreadable/odd region must never crash the job.
			$this->logInfo('Manual face ' . $faceId . ': could not compute a descriptor (' . $e->getMessage() . '), excluding it from clustering');
			$this->logDebug((string) $e);
			$this->faceMapper->markManualFaceNotGroupable($faceId);
		} finally {
			// Clean up any temporary files (crop + external file copies).
			$this->tempManager->clean();
			$this->fileService->clean();
		}
	}

	/**
	 * Crop the marked region (plus a margin) from the original image and save it
	 * to a temporary file. Coordinates are original-image pixels in the oriented
	 * frame, so the image is orientation-fixed before cropping.
	 *
	 * @return string|null path to the cropped temp file, or null on failure
	 */
	private function cropRegion(string $localPath, string $mimeType, int $x, int $y, int $w, int $h): ?string {
		$image = new OCP_Image();
		if ($image->loadFromFile($localPath) === false) {
			return null;
		}
		$image->fixOrientation();
		if (!$image->valid()) {
			return null;
		}

		$imgW = $image->width();
		$imgH = $image->height();
		if ($imgW <= 0 || $imgH <= 0 || $w <= 0 || $h <= 0) {
			return null;
		}

		$marginX = (int) round($w * self::CROP_MARGIN);
		$marginY = (int) round($h * self::CROP_MARGIN);

		$cropX = max(0, $x - $marginX);
		$cropY = max(0, $y - $marginY);
		$cropRight = min($imgW, $x + $w + $marginX);
		$cropBottom = min($imgH, $y + $h + $marginY);

		$cropW = $cropRight - $cropX;
		$cropH = $cropBottom - $cropY;
		if ($cropW <= 0 || $cropH <= 0) {
			return null;
		}

		if ($image->crop($cropX, $cropY, $cropW, $cropH) === false) {
			return null;
		}

		$cropPath = $this->tempManager->getTemporaryFile();
		if ($image->save($cropPath, $mimeType) === false) {
			return null;
		}

		return $cropPath;
	}

	/**
	 * Pick the largest detected face (by bounding-box area) from a detection
	 * result, assuming the user centred the rectangle on the intended face.
	 *
	 * @param array<int, array<string, mixed>> $rawFaces
	 * @return array<string, mixed> the best face, or [] if none
	 */
	private function pickLargestFace(array $rawFaces): array {
		$best = [];
		$bestArea = -1;
		foreach ($rawFaces as $face) {
			$width = max(0, (int) $face['right'] - (int) $face['left']);
			$height = max(0, (int) $face['bottom'] - (int) $face['top']);
			$area = $width * $height;
			if ($area > $bestArea) {
				$bestArea = $area;
				$best = $face;
			}
		}
		return $best;
	}
}

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

use OCP\ITempManager;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ManualRegionMapper;

use OCA\FaceRecognition\Helper\FaceParticipation;
use OCA\FaceRecognition\Helper\FaceRect;
use OCA\FaceRecognition\Helper\ManualFaceDetector;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Task that searches the regions the user marked for faces again, and creates
 * every face it finds there, not only the biggest one: a region is drawn around
 * a group of faces that the analysis of the whole photo saw too small.
 *
 * The faces are created with what the detection gives them, and left for the
 * clustering to place like the ones of the analysis. The ones that are too
 * small or too uncertain are created as well, so that they are on the photo and
 * can be named by hand; the clustering leaves them out, and the region counts
 * them, so the user can tell why.
 *
 * Nothing that goes wrong here leaves the task, for the same reason as in
 * ManualFaceDescriptorTask. A region that fails is recorded as failed, with the
 * reason, and it is not tried again.
 */
class ManualRegionTask extends FaceRecognitionBackgroundTask {

	/**
	 * Margin kept around a region, in pixels of the original photo, on every
	 * side. A region already holds its faces with what is around them, so the
	 * margin of a single marking, proportional to its size, would only spend
	 * the area the model can take: a region of 1000x650 would grow to 2.1
	 * megapixels, which with 2 GB for the model is already scaled down again.
	 * With a fixed margin its share shrinks as the region grows.
	 */
	public const REGION_MARGIN = 64;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ManualRegionMapper */
	private $regionMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var ModelManager */
	private $modelManager;

	/** @var ManualFaceDetector */
	private $detector;

	public function __construct(FaceMapper         $faceMapper,
	                            ManualRegionMapper $regionMapper,
	                            FileService        $fileService,
	                            SettingsService    $settingsService,
	                            ModelManager       $modelManager,
	                            ITempManager       $tempManager)
	{
		parent::__construct();

		$this->faceMapper      = $faceMapper;
		$this->regionMapper    = $regionMapper;
		$this->settingsService = $settingsService;
		$this->modelManager    = $modelManager;
		$this->detector        = new ManualFaceDetector($fileService, $tempManager);
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Search the regions marked by hand for faces";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		// A failure outside of a single region, like opening the model or
		// reading what is pending, costs this task and nothing else of the run.
		try {
			yield from $this->searchPendingRegions();
		} catch (\Throwable $e) {
			$this->warn('The regions marked by hand could not be searched, they are left for the next run: ' . $e->getMessage());
			$this->logDebug((string) $e);
		}

		return true;
	}

	private function searchPendingRegions(): \Generator {
		if (!$this->regionMapper->isAvailable() || !$this->faceMapper->hasManualStateColumn()) {
			$this->warn('Skipping the regions marked by hand: the database migration of the app did not run yet');
			return;
		}

		$orphaned = $this->regionMapper->deleteOrphaned();
		if ($orphaned > 0) {
			$this->log('Deleted ' . $orphaned . ' region(s) of photos that are gone');
		}

		$model = $this->modelManager->getCurrentModel();
		if (is_null($model)) {
			$this->log('No current model configured, skipping the regions marked by hand');
			return;
		}

		$modelId = $this->settingsService->getCurrentFaceModel();
		$minFaceSize = $this->settingsService->getMinimumFaceSize();
		$minConfidence = $this->settingsService->getMinimumConfidence();

		$opened = false;
		foreach ($this->context->getEligibleUsers() as $userId) {
			$pending = $this->regionMapper->findPending($userId, $modelId);
			if (count($pending) === 0) {
				continue;
			}

			// Only open the (expensive) model when there is actually work to do.
			if (!$opened) {
				$model->open();
				$opened = true;
			}

			$this->log('Searching ' . count($pending) . ' region(s) of user ' . $userId);
			foreach ($pending as $row) {
				$this->searchRegion($model, $userId, $row, $minFaceSize, $minConfidence);
				yield;
			}
		}
	}

	/**
	 * Searches a single region, creates the faces it finds, and records the
	 * result on the region. Never throws.
	 *
	 * @param array<string, mixed> $row id, image, file, x, y, width, height
	 */
	private function searchRegion(IModel $model, string $userId, array $row, int $minFaceSize, float $minConfidence): void {
		$regionId = (int) $row['id'];
		$imageId = (int) $row['image'];

		try {
			$faces = $this->detector->detect($model, $userId, (int) $row['file'], [
				'x'      => (int) $row['x'],
				'y'      => (int) $row['y'],
				'width'  => (int) $row['width'],
				'height' => (int) $row['height'],
			], self::REGION_MARGIN, self::REGION_MARGIN);

			// A find in the place of a face the photo already has is that same
			// face, found again, and it is left as it is.
			$taken = [];
			foreach ($this->faceMapper->findByImage($imageId) as $existing) {
				$taken[] = ManualFaceDetector::edges([
					'x' => $existing->getX(),
					'y' => $existing->getY(),
					'width' => $existing->getWidth(),
					'height' => $existing->getHeight(),
				]);
			}

			$found = 0;
			$tooSmall = 0;
			$lowConfidence = 0;
			foreach ($faces as $detected) {
				if (empty($detected['descriptor'])) {
					continue;
				}

				$edges = ManualFaceDetector::edges($detected);
				if (self::isTaken($edges, $taken)) {
					continue;
				}

				$face = new Face();
				$face->image = $imageId;
				$face->x = $detected['x'];
				$face->y = $detected['y'];
				$face->width = $detected['width'];
				$face->height = $detected['height'];
				$face->confidence = $detected['confidence'];
				$face->landmarks = $detected['landmarks'];
				$face->descriptor = $detected['descriptor'];
				$face->setCreationTime(new \DateTime());
				$this->faceMapper->insertRescanFace($face);

				$taken[] = $edges;
				$found++;

				$reason = FaceParticipation::qualityReason($detected['width'], $detected['height'],
					$detected['confidence'], $minFaceSize, $minConfidence);
				if ($reason === FaceParticipation::REASON_TOO_SMALL) {
					$tooSmall++;
				} elseif ($reason === FaceParticipation::REASON_LOW_CONFIDENCE) {
					$lowConfidence++;
				}
			}

			$this->regionMapper->markDone($regionId, $found, $tooSmall, $lowConfidence);
			$this->log('Region ' . $regionId . ': ' . $found . ' new face(s), of them ' . $tooSmall . ' too small and ' . $lowConfidence . ' below the minimum confidence');
		} catch (\Throwable $e) {
			$this->warn('Region ' . $regionId . ' on file ' . $row['file'] . ' of user ' . $userId . ': could not be searched (' . $e->getMessage() . ')');
			$this->logDebug((string) $e);
			$this->giveUp($regionId, $e->getMessage());
		} finally {
			// Clean up any temporary files (crop + external file copies).
			try {
				$this->detector->clean();
			} catch (\Throwable $e) {
				$this->warn('Region ' . $regionId . ': could not remove the temporary files (' . $e->getMessage() . ')');
			}
		}
	}

	/**
	 * Whether a box takes the place of one of the given boxes, with the same
	 * overlap from which the analysis takes two finds for the same face.
	 *
	 * @param array{left: int, right: int, top: int, bottom: int} $edges
	 * @param array<int, array{left: int, right: int, top: int, bottom: int}> $taken
	 */
	private static function isTaken(array $edges, array $taken): bool {
		foreach ($taken as $other) {
			if (FaceRect::overlapPercent($edges, $other) >= FaceRect::SAME_FACE_MIN_OVERLAP) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Records that the region failed, so that it is not taken again on every
	 * run. If even that fails, it is left pending for the next one, and the
	 * other regions are still searched.
	 */
	private function giveUp(int $regionId, string $reason): void {
		try {
			$this->regionMapper->markFailed($regionId, $reason);
		} catch (\Throwable $e) {
			$this->warn('Region ' . $regionId . ': could not record that it failed either (' . $e->getMessage() . '), it is left for the next run');
			$this->logDebug((string) $e);
		}
	}

	private function log(string $message): void {
		$this->logInfo(ManualFaceDetector::LOG_PREFIX . $message);
	}

	/**
	 * What went wrong, as a warning: in the Nextcloud log at its default level
	 * when the job runs from cron, where the info lines are not.
	 */
	private function warn(string $message): void {
		$this->logWarning(ManualFaceDetector::LOG_PREFIX . $message);
	}
}

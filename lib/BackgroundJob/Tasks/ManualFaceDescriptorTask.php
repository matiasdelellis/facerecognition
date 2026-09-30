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

use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Helper\FaceRect;
use OCA\FaceRecognition\Helper\ManualFaceDetector;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Task that searches the faces the user marked by hand for a descriptor. A
 * manual face carries no model descriptor, so it cannot participate in
 * clustering on its own. For each marking that waits for it, this task crops
 * the marked region from the original photo and runs face detection on just
 * that crop (see ManualFaceDetector).
 *
 * If a face is found, the marking takes its descriptor, its box and the
 * confidence the detector gave it, and from then on the clustering treats it
 * like any other face, minimums included. If that face is on the photo
 * already, found by the analysis or marked before, the marking is merged into
 * it instead (see ManualFaceService::mergeMarking()), so the user does not see
 * the same face twice. If no face can be detected on the
 * marked region, only in the margin around it, or none at all, the face is
 * simply excluded from clustering: it stays pinned
 * to its person, if it has one. No fallback descriptor is fabricated.
 *
 * Nothing that goes wrong here leaves the task: the background job treats any
 * exception of a task as fatal, and would stop the analysis and the clustering
 * of every user with it. A marking that fails is recorded as having no face,
 * so it is not tried again on every run.
 */
class ManualFaceDescriptorTask extends FaceRecognitionBackgroundTask {

	/**
	 * Extra context kept around the user rectangle before detection, as a
	 * fraction of the rectangle size. Detectors work better with some margin.
	 */
	private const CROP_MARGIN = 0.4;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var ModelManager */
	private $modelManager;

	/** @var ManualFaceDetector */
	private $detector;

	/** @var ManualFaceService */
	private $manualFaces;

	public function __construct(FaceMapper        $faceMapper,
	                            FileService       $fileService,
	                            SettingsService   $settingsService,
	                            ModelManager      $modelManager,
	                            ITempManager      $tempManager,
	                            ManualFaceService $manualFaces)
	{
		parent::__construct();

		$this->faceMapper      = $faceMapper;
		$this->settingsService = $settingsService;
		$this->modelManager    = $modelManager;
		$this->detector        = new ManualFaceDetector($fileService, $tempManager);
		$this->manualFaces     = $manualFaces;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Compute descriptors for the faces marked by hand";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		// A failure outside of a single marking, like opening the model or
		// reading what is pending, costs this task and nothing else of the run.
		try {
			yield from $this->searchPendingMarkings();
		} catch (\Throwable $e) {
			$this->warn('The faces marked by hand could not be searched, they are left for the next run: ' . $e->getMessage());
			$this->logDebug((string) $e);
		}

		return true;
	}

	private function searchPendingMarkings(): \Generator {
		if (!$this->faceMapper->hasManualStateColumn()) {
			$this->warn('Skipping the faces marked by hand: the database migration of the app did not run yet');
			return;
		}

		$model = $this->modelManager->getCurrentModel();
		if (is_null($model)) {
			$this->log('No current model configured, skipping manual face descriptor extraction');
			return;
		}

		$modelId = $this->settingsService->getCurrentFaceModel();

		$opened = false;
		foreach ($this->context->getEligibleUsers() as $userId) {
			$this->mergeDuplicatesOnce($userId, $modelId);

			$pending = $this->faceMapper->findManualFacesPendingDescriptor($userId, $modelId);
			if (count($pending) === 0) {
				continue;
			}

			// Only open the (expensive) model when there is actually work to do.
			if (!$opened) {
				$model->open();
				$opened = true;
			}

			$this->log('Computing descriptors for ' . count($pending) . ' manual face(s) of user ' . $userId);
			foreach ($pending as $row) {
				$this->computeDescriptorForFace($model, $userId, $row);
				yield;
			}
		}
	}

	/**
	 * Compute and store the descriptor for a single pending manual face, or
	 * exclude it from clustering if no face is found. Never throws.
	 *
	 * @param array<string, mixed> $row id, file, x, y, width, height
	 */
	private function computeDescriptorForFace(IModel $model, string $userId, array $row): void {
		$faceId = (int) $row['id'];
		$drawn = [
			'x'      => (int) $row['x'],
			'y'      => (int) $row['y'],
			'width'  => (int) $row['width'],
			'height' => (int) $row['height'],
		];

		try {
			$faces = $this->detector->detect($model, $userId, (int) $row['file'], $drawn,
				(int) round($drawn['width'] * self::CROP_MARGIN),
				(int) round($drawn['height'] * self::CROP_MARGIN),
				min($drawn['width'], $drawn['height']));

			if (count($faces) === 0) {
				$this->log('Manual face ' . $faceId . ': no face detected in the marked region, excluding it from clustering');
				$this->giveUp($faceId);
				return;
			}

			$best = self::pickMarkedFace($drawn, $faces);
			if (is_null($best)) {
				$this->log('Manual face ' . $faceId . ': the faces detected are all in the margin around the marked region, none on it, excluding it from clustering');
				$this->giveUp($faceId);
				return;
			}
			if (empty($best['descriptor'])) {
				$this->log('Manual face ' . $faceId . ': the face detected in the marked region has no descriptor, excluding it from clustering');
				$this->giveUp($faceId);
				return;
			}

			// The box is replaced with the one the descriptor was actually
			// computed from, so that what the frontend shows is what the
			// clustering compares. The face picked touches the drawn box, but a
			// loose or misplaced box may overlap it only a little; when the two
			// do not overlap like two finds of the same face, the user is told
			// the box moved.
			$boxAdjusted = FaceRect::overlapPercent(ManualFaceDetector::edges($drawn), ManualFaceDetector::edges($best))
				< FaceRect::SAME_FACE_MIN_OVERLAP;

			if ($this->mergeIntoSameFace($userId, $row, $best)) {
				return;
			}

			$this->faceMapper->setManualFaceDescriptor(
				$faceId, $best['descriptor'],
				$best['x'], $best['y'], $best['width'], $best['height'],
				$best['confidence'], $boxAdjusted
			);
			$this->log('Manual face ' . $faceId . ': descriptor computed with a confidence of ' . $best['confidence']);
		} catch (\Throwable $e) {
			// Robustness: a single unreadable/odd region must never crash the job.
			$this->warn('Manual face ' . $faceId . ' on file ' . $row['file'] . ' of user ' . $userId . ': could not compute a descriptor (' . $e->getMessage() . '), excluding it from clustering');
			$this->logDebug((string) $e);
			$this->giveUp($faceId);
		} finally {
			// Clean up any temporary files (crop + external file copies).
			try {
				$this->detector->clean();
			} catch (\Throwable $e) {
				$this->warn('Manual face ' . $faceId . ': could not remove the temporary files (' . $e->getMessage() . ')');
			}
		}
	}

	/**
	 * Merges the marking into the face the search found, if that face is on
	 * the photo already: found by the analysis, or marked before. A marking
	 * the user ignored is kept as it is.
	 *
	 * @param array<string, mixed> $row the marking: id, image, cluster
	 * @param array<string, mixed> $found the face the search found, in pixels of the photo
	 *
	 * @return bool whether the marking was merged, and is gone
	 */
	private function mergeIntoSameFace(string $userId, array $row, array $found): bool {
		$markingCluster = is_null($row['cluster']) ? null : (int) $row['cluster'];
		if ($this->manualFaces->isIgnored($userId, $markingCluster)) {
			return false;
		}

		$same = ManualFaceService::sameFace($found, $this->faceMapper->findComparableFacesOfImage((int) $row['image']));
		if (is_null($same)) {
			return false;
		}

		$tookName = $this->manualFaces->mergeMarking($userId, (int) $row['id'], $markingCluster, $same);
		$this->log('Manual face ' . $row['id'] . ': the face it marks is face ' . $same->getId() . ', which was on the photo already; the marking is merged into it'
			. ($tookName ? ', and that face takes its name' : ''));
		return true;
	}

	/**
	 * Merges, once for each user, the markings that were searched before the
	 * search merged them and turned out to be a face already on the photo. A
	 * failure is logged, and it is tried again on the next run.
	 */
	private function mergeDuplicatesOnce(string $userId, int $modelId): void {
		if ($this->settingsService->getManualDuplicatesMerged($userId)) {
			return;
		}
		try {
			$merged = $this->manualFaces->mergeFoundMarkings($userId, $modelId);
			$this->settingsService->setManualDuplicatesMerged($userId);
			if ($merged > 0) {
				$this->log('Merged ' . $merged . ' marking(s) of user ' . $userId . ' into the face that was on the photo already');
			}
		} catch (\Throwable $e) {
			$this->warn('The markings of user ' . $userId . ' that are a face already on the photo could not be merged, they are left for the next run: ' . $e->getMessage());
			$this->logDebug((string) $e);
		}
	}

	/**
	 * Records that the search found nothing in the marked region, so that the
	 * face is not taken again on every run. If even that fails, it is left
	 * pending for the next one, and the other faces are still searched.
	 */
	private function giveUp(int $faceId): void {
		try {
			$this->faceMapper->markManualFaceNotGroupable($faceId);
		} catch (\Throwable $e) {
			$this->warn('Manual face ' . $faceId . ': could not record that it has no face either (' . $e->getMessage() . '), it is left for the next run');
			$this->logDebug((string) $e);
		}
	}

	/**
	 * The face the user marked, of the ones detected in the crop: the one
	 * that overlaps the drawn box the most. The biggest one would be wrong
	 * whenever a bigger face sits in the margin, and its descriptor would then
	 * be stored for the person of the marking. A face that does not touch the
	 * drawn box at all is only in the margin, and never the marked one, so if
	 * every face is like that, there is none.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $drawn
	 * @param array<int, array<string, mixed>> $faces faces in pixels of the original photo
	 * @return array<string, mixed>|null the marked face, or null if none is on the drawn box
	 */
	public static function pickMarkedFace(array $drawn, array $faces): ?array {
		$drawnEdges = ManualFaceDetector::edges($drawn);
		$best = null;
		$bestOverlap = 0.0;
		foreach ($faces as $face) {
			$overlap = FaceRect::overlapPercent($drawnEdges, ManualFaceDetector::edges($face));
			if ($overlap > $bestOverlap) {
				$bestOverlap = $overlap;
				$best = $face;
			}
		}
		return $best;
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

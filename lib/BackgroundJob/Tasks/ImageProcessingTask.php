<?php
/**
 * @copyright Copyright (c) 2017-2020 Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCP\Image as OCP_Image;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\ILockingProvider;
use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Helper\FaceRect;
use OCA\FaceRecognition\Helper\ManualFaceDetector;
use OCA\FaceRecognition\Helper\TempImage;

use OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel;
use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Taks that get all images that are still not processed and processes them.
 * Processing image means that each image is prepared, faces extracted form it,
 * and for each found face - face descriptor is extracted.
 *
 * The analysis happens in two passes. The fast pass (--fast-mode) uses the HOG
 * model on a small image, so that groupings and persons appear quickly. The
 * refinement pass uses the current model at maximum resolution, replacing the
 * faces of each image with higher quality ones; the new faces keep the cluster
 * of the old face found in the same place, so a person is never lost.
 */
class ImageProcessingTask extends FaceRecognitionBackgroundTask {

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/** @var FaceMapper Face mapper*/
	protected $faceMapper;

	/** @var FileService */
	protected $fileService;

	/** @var SettingsService */
	protected $settingsService;

	/** @var ModelManager $modelManager */
	protected $modelManager;

	/** @var ILockingProvider $lockingProvider */
	protected ILockingProvider $lockingProvider;

	/** @var IModel $model */
	private $model;

	/** @var int|null $maxImageAreaCached Maximum image area (cached, so it is not recalculated for each image) */
	private $maxImageAreaCached;

	/** @var bool Whether the faces marked by hand can be told apart in this run */
	private $manualFacesKnown = false;


	/**
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FaceMapper $faceMapper Face mapper
	 * @param FileService $fileService
	 * @param SettingsService $settingsService
	 * @param ModelManager $modelManager Model manager
	 * @param ILockingProvider $lockingProvider
	 */
	public function __construct(ImageMapper      $imageMapper,
	                            FaceMapper       $faceMapper,
	                            FileService      $fileService,
	                            SettingsService  $settingsService,
	                            ModelManager     $modelManager,
	                            ILockingProvider $lockingProvider)
	{
		parent::__construct();

		$this->imageMapper        = $imageMapper;
		$this->faceMapper         = $faceMapper;
		$this->fileService        = $fileService;
		$this->settingsService    = $settingsService;
		$this->modelManager       = $modelManager;
		$this->lockingProvider    = $lockingProvider;

		$this->model              = null;
		$this->maxImageAreaCached = null;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Process all images to extract faces";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		$this->logInfo('NOTE: Starting face recognition. If you experience random crashes after this point, please look FAQ at https://github.com/matiasdelellis/facerecognition/wiki/FAQ');

		// The fast pass uses the HOG model on a small image, the refinement pass
		// the current model at maximum quality.
		if ($this->context->isRunningInFastMode()) {
			$fastModel = $this->modelManager->getModel(DlibHogModel::FACE_MODEL_ID);
			$currentModel = $this->modelManager->getCurrentModel();

			// The fast pass writes its faces in the rows of the current model,
			// and the refinement replaces them with the faces of that model, so
			// both passes must produce comparable descriptors. The HOG model
			// agrees with the models that align the face and compute the
			// descriptor the same way (models 1 and 4), but not with the ones
			// that align it with the 68-point predictor (model 2) or use
			// another network (model 6). With a current model that is not
			// comparable, the fast pass falls back to it: slower, but the
			// fast-pass faces stay comparable with the refined ones.
			$fastPassUsesHog = !is_null($currentModel) &&
			                   ($currentModel->getDescriptorType() === $fastModel->getDescriptorType());
			$this->model = $fastPassUsesHog ? $fastModel : ($currentModel ?? $fastModel);
			if (!$fastPassUsesHog && !is_null($currentModel)) {
				$this->logInfo('Fast pass: the HOG descriptors are not comparable with the ones of the ' . $this->model->getName() . ' model, using the current model for the fast pass');
			}

			// The model of the fast pass must be installed and usable. The files
			// of each model live in their own folder, so having another model
			// installed does not install this one, and the fast pass is not the
			// place to download it: the admin does that with face:setup.
			$modelError = '';
			if (!$this->model->isInstalled()) {
				throw new \RuntimeException('The fast pass cannot run: the ' . $this->model->getName() . ' model is not installed. Install it with the occ face:setup -m ' . $this->model->getId() . ' command.');
			}
			if (!$this->model->meetDependencies($modelError)) {
				throw new \RuntimeException('The fast pass cannot run: ' . $modelError);
			}
			$this->logInfo('Fast pass: using the ' . $this->model->getName() . ' model on a small image');
		} else {
			$this->model = $this->modelManager->getCurrentModel();
		}

		// Open model.
		$this->model->open();

		$refined = !$this->context->isRunningInFastMode();

		$this->manualFacesKnown = $this->faceMapper->hasManualStateColumn();
		if (!$this->manualFacesKnown) {
			$this->logWarning(ManualFaceDetector::LOG_PREFIX . 'The faces marked by hand have no state yet, since the database migration of the app did not run: the found faces are not matched with them');
		}
		$images = $context->propertyBag['images'];
		foreach($images as $image) {
			yield;

			$startMillis = round(microtime(true) * 1000);

			$lockKey = 'facerecognition/' . $image->getId();
			$lockType = ILockingProvider::LOCK_EXCLUSIVE;
			$lockAcquired = false;

			try {
				// Get a image lock
				$this->lockingProvider->acquireLock($lockKey, $lockType);
				$lockAcquired = true;

				$dbImage = $this->imageMapper->find($image->getUser(), $image->getId());

				// An image is done when it was processed, and in the refinement
				// pass it also has to have been refined. The images that were
				// only analyzed in the fast pass have to be taken again.
				$alreadyDone = $refined ? $dbImage->getIsRefined() : $dbImage->getIsProcessed();
				if ($alreadyDone) {
					$this->logInfo('Faces found: 0. Image will be skipped since it was already processed.');
					continue;
				}

				// Another user may have already analyzed this very file: a
				// shared photo keeps the file id of its owner in every account,
				// so it shows up in the tables of several users with the same
				// file id. When an analyzed copy exists, reuse its faces instead
				// of running the model again. In the refinement pass only a
				// refined result is good enough: reusing a fast-pass one would
				// leave this image pending for ever.
				$reusedFrom = $this->imageMapper->findProcessedDuplicate($image->getFile(), $image->getModel(), $image->getUser());
				if (!is_null($reusedFrom) && (!$refined || $reusedFrom->getIsRefined())) {
					$faces = $this->faceMapper->copyFaces($reusedFrom->getId(), $image->getId());

					// Like the faces that the model finds in the refinement,
					// the reused ones replace the fast-pass faces, carrying
					// the cluster of the face found in the same place so a
					// person is never lost.
					$faces = $this->reconcileWithOldFaces($image, $faces, $refined);

					$endMillis = round(microtime(true) * 1000);
					$duration = (int) max($endMillis - $startMillis, 0);
					$this->imageMapper->imageProcessed($image, $faces, $duration, null, $reusedFrom->getIsRefined());

					$this->logInfo('Faces found: ' . count($faces) . ' (reused from the analysis of the user ' . $reusedFrom->getUser() . ')');
					continue;
				}

				// Get an temp Image to process this image.
				$tempImage = $this->getTempImage($image);

				if (is_null($tempImage)) {
					// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
					$this->settingsService->setNeedRemoveStaleImages(true, $image->user);
					$this->logInfo('File with ID ' . $image->file . ' doesn\'t exist anymore, skipping it');
					continue;
				}

				if ($tempImage->getSkipped() === true) {
					$this->logInfo('Faces found: 0 (image will be skipped because it is too small)');
					// Keep the faces that were already found, if any, and mark
					// the image as done for this pass.
					$this->imageMapper->imageProcessed($image, array(), 0, null, $refined, false);
					continue;
				}

				// Get faces in the temporary image
				$tempImagePath = $tempImage->getTempPath();
				$rawFaces = $this->model->detectFaces($tempImagePath);

				$this->logInfo('Faces found: ' . count($rawFaces));

				$faces = array();
				foreach ($rawFaces as $rawFace) {
					// Normalize face and landmarks from model to original size
					$normFace = $this->getNormalizedFace($rawFace, $tempImage->getRatio());
					// Convert from dictionary of face to our Face Db Entity.
					$face = Face::fromModel($image->getId(), $normFace);
					// Save the normalized Face to insert on database later.
					$faces[] = $face;
				}

				// The new faces replace the fast-pass ones, but the faces
				// found again in the same place keep their cluster, and with
				// it the person the user gave the cluster. The faces the user
				// put there are matched as well, see reconcileWithOldFaces().
				$faces = $this->reconcileWithOldFaces($image, $faces, $refined);

				// Save new faces fo database
				$endMillis = round(microtime(true) * 1000);
				$duration = (int) max($endMillis - $startMillis, 0);
				$this->imageMapper->imageProcessed($image, $faces, $duration, null, $refined);
			} catch (\OCP\Lock\LockedException $e) {
				$this->logInfo('Faces found: 0. Image will be skipped because it is locked');
			} catch (\Exception $e) {
				if ($e->getMessage() === "std::bad_alloc") {
					throw new \RuntimeException("Not enough memory to run face recognition! Please look FAQ at https://github.com/matiasdelellis/facerecognition/wiki/FAQ");
				}
				$this->logInfo('Faces found: 0. Image will be skipped because of the following error: ' . $e->getMessage());
				$this->logDebug((string) $e);

				// Record the error, without touching the faces: the image keeps
				// the ones it had, and with them the person. It is not taken
				// again until the user resets the errors, so a file that can
				// never be analyzed does not cost every run.
				$this->imageMapper->imageProcessed($image, array(), 0, $e, $refined, false);
			} finally {
				// Release lock of file, whenever it was acquired. The previous
				// code only released it on the happy paths, so an image that
				// failed was left locked and skipped on every later run.
				if ($lockAcquired) {
					$this->lockingProvider->releaseLock($lockKey, $lockType);
				}
				// Clean temporary image.
				if (isset($tempImage)) {
					$tempImage->clean();
				}
				// If there are temporary files from external files, they must also be cleaned.
				$this->fileService->clean();
			}
		}

		return true;
	}

	/**
	 * Given an image, build a temporary image to perform the analysis
	 *
	 * return TempImage|null
	 */
	private function getTempImage(Image $image): ?TempImage {
		// todo: check if this hits I/O (database, disk...), consider having lazy caching to return user folder from user
		$file = $this->fileService->getFileById($image->getFile(), $image->getUser());
		if (empty($file)) {
			return null;
		}

		if (!$this->fileService->isAllowedNode($file)) {
			return null;
		}

		$imagePath = $this->fileService->getLocalFile($file);
		if ($imagePath === null)
			return null;

		$this->logInfo('Processing image ' . $imagePath);

		$tempImage = new TempImage($imagePath,
		                           $this->model->getPreferredMimeType(),
		                           $this->getMaxImageArea(),
		                           $this->settingsService->getMinimumImageSize());

		return $tempImage;
	}

	/**
	 * Obtains max image area lazily (from cache, or calculates it and puts it to cache)
	 *
	 * @return int Max image area (in pixels^2)
	 */
	private function getMaxImageArea(): int {
		// First check if is cached
		//
		if (!is_null($this->maxImageAreaCached)) {
			return $this->maxImageAreaCached;
		}

		// The fast pass works on a small image, to be quick and to need no
		// memory; the refinement pass uses the configured analysis area.
		//
		$fastMode = $this->context->isRunningInFastMode();
		if ($fastMode) {
			$area = $this->settingsService->getFastPassImageArea();
		} else {
			// Get this setting on main app_config.
			// Note that this option has lower and upper limits and validations
			$area = $this->settingsService->getAnalysisImageArea();
		}

		// The overrides below replace the area of the analysis, but in the fast
		// pass they are only a ceiling: they are there to keep an image from
		// being too big, and an override bigger than the fast pass area would
		// make the fast pass work on a bigger image than it asked for, which is
		// the opposite of being fast.
		//
		// Check if admin override it in config and it is valid value
		//
		$maxImageArea = $this->settingsService->getMaximumImageArea();
		if ($maxImageArea > 0) {
			$area = $fastMode ? min($area, $maxImageArea) : $maxImageArea;
		}
		// Also check if we are provided value from command line.
		//
		if ((array_key_exists('max_image_area', $this->context->propertyBag)) &&
		    (!is_null($this->context->propertyBag['max_image_area']))) {
			$commandArea = $this->context->propertyBag['max_image_area'];
			$area = $fastMode ? min($area, $commandArea) : $commandArea;
		}

		$this->maxImageAreaCached = $area;

		return $this->maxImageAreaCached;
	}

	/**
	 * Helper method, to normalize face sizes back to original dimensions, based on ratio
	 *
	 */
	private function getNormalizedFace(array $rawFace, float $ratio): array {
		$face = [];
		$face['left'] = intval(round($rawFace['left']*$ratio));
		$face['right'] = intval(round($rawFace['right']*$ratio));
		$face['top'] = intval(round($rawFace['top']*$ratio));
		$face['bottom'] = intval(round($rawFace['bottom']*$ratio));
		$face['detection_confidence'] = $rawFace['detection_confidence'];
		$face['landmarks'] = $this->getNormalizedLandmarks($rawFace['landmarks'], $ratio);
		$face['descriptor'] = $rawFace['descriptor'];
		return $face;
	}

	/**
	 * Helper method, to normalize landmarks sizes back to original dimensions, based on ratio
	 *
	 */
	private function getNormalizedLandmarks(array $rawLandmarks, float $ratio): array {
		$landmarks = [];
		foreach ($rawLandmarks as $rawLandmark) {
			$landmark = [];
			$landmark['x'] = intval(round($rawLandmark['x']*$ratio));
			$landmark['y'] = intval(round($rawLandmark['y']*$ratio));
			$landmarks[] = $landmark;
		}
		return $landmarks;
	}

	/**
	 * Carries the cluster of the old faces of the image to the new ones, so that
	 * the refinement pass does not lose the person that the user named.
	 *
	 * The faces of both passes are stored in the coordinates of the original
	 * image, so a new face that overlaps an old one is the same face of the same
	 * person, and it keeps the cluster, and with it the person.
	 */
	private function inheritClusters(Image $image, array $faces): void {
		$oldFaces = $this->faceMapper->findByImage($image->getId());
		if (count($oldFaces) === 0) {
			return;
		}

		$old = [];
		foreach ($oldFaces as $oldFace) {
			$old[] = [
				'left' => $oldFace->getX(),
				'right' => $oldFace->getX() + $oldFace->getWidth(),
				'top' => $oldFace->getY(),
				'bottom' => $oldFace->getY() + $oldFace->getHeight(),
				'cluster' => $oldFace->getCluster(),
				'is_groupable' => $oldFace->getIsGroupable(),
			];
		}

		$new = [];
		foreach ($faces as $face) {
			$new[] = [
				'left' => $face->getX(),
				'right' => $face->getX() + $face->getWidth(),
				'top' => $face->getY(),
				'bottom' => $face->getY() + $face->getHeight(),
			];
		}

		$assigned = FaceRect::matchClusters($new, $old);
		foreach ($assigned as $newIndex => $inheritance) {
			if (is_null($inheritance['cluster'])) {
				// The old face was not clustered yet: nothing to inherit.
				continue;
			}
			$faces[$newIndex]->setCluster($inheritance['cluster']);
			if (!$inheritance['is_groupable']) {
				// The old face was a face the user detached: the new one keeps
				// its cluster and stays non-groupable, so that the clustering
				// does not put it back where the user took it out of.
				$faces[$newIndex]->setIsGroupable(false);
			}
		}
	}

	/**
	 * What the faces the analysis found do to the faces the image already has,
	 * and which of them are left to insert.
	 *
	 * The faces the user put there, which are the ones marked by hand, the
	 * ones found in a region the user marked and the ones moved to another
	 * person, are not replaced by imageProcessed(). When the analysis finds
	 * one of them by itself, there would be two faces in the same place, so
	 * the analysis wins instead: what it found is written into the row of the
	 * face the user put there (see resolveManualFaces()).
	 *
	 * This sits in the path of every photo, so nothing of it may keep a photo
	 * from being processed. If it fails, the photo is processed as it was
	 * before this existed: two faces in the same place are better than a photo
	 * that records an error, since such a photo is not analyzed again until
	 * the user resets the errors.
	 *
	 * That is also why the faces put there by hand are written before the
	 * photo and not after it: a failure here can still fall back, while one
	 * after imageProcessed() could not undo what it did. The other way round,
	 * a failure of imageProcessed() after the takeover is the usual failure of
	 * a photo: it records the error, keeps its other faces, and is analyzed
	 * again once the errors are reset, which takes the same faces over again.
	 *
	 * @param Face[] $faces Faces the analysis found
	 *
	 * @return Face[] Faces to insert
	 */
	private function reconcileWithOldFaces(Image $image, array $faces, bool $refined): array {
		if ($this->manualFacesKnown) {
			try {
				return $this->resolveManualFaces($image, $faces, $refined);
			} catch (\Throwable $e) {
				$this->logWarning(ManualFaceDetector::LOG_PREFIX . 'Image ' . $image->getId() . ': the found faces could not be matched with the ones put there by hand (' . $e->getMessage() . '), processing it as before');
				$this->logDebug((string) $e);
			}
		}

		if ($refined) {
			$this->inheritClusters($image, $faces);
		}
		return $faces;
	}

	/**
	 * Matches the faces the analysis found with the old faces of the image, in
	 * one go, and decides for each match.
	 *
	 * A face of the analysis found again keeps its cluster in the refinement,
	 * as inheritClusters() does, and is inserted.
	 *
	 * A face the user put there is overwritten with what the analysis found,
	 * which keeps its id, its cluster and its protection from being replaced.
	 * A marking records that the analysis confirmed it, and if its own search
	 * had found no face, it gets back into the clustering: the analysis found
	 * one. A face the user took out of its group stays out of the clustering,
	 * marking or not: that is a decision of the user, and nothing the analysis
	 * finds changes it.
	 *
	 * Only the refinement overwrites. The fast pass works on a deliberately
	 * small image, and its descriptor must not replace one computed on a
	 * region that was scaled up; there the find is dropped, and the face the
	 * user put there stays as it is. The fast pass only matches with those
	 * faces: the others are replaced as a whole, found again or not.
	 *
	 * @param Face[] $faces Faces the analysis found
	 *
	 * @return Face[] Faces to insert
	 */
	private function resolveManualFaces(Image $image, array $faces, bool $refined): array {
		$manualFaces = [];
		foreach ($this->faceMapper->findManualFacesOfImage($image->getId()) as $manualFace) {
			$manualFaces[$manualFace->getId()] = $manualFace;
		}

		$oldFaces = $refined ? $this->faceMapper->findByImage($image->getId()) : array_values($manualFaces);
		if (count($oldFaces) === 0) {
			return $faces;
		}

		$old = [];
		foreach ($oldFaces as $oldFace) {
			$old[] = ManualFaceDetector::edges([
				'x' => $oldFace->getX(),
				'y' => $oldFace->getY(),
				'width' => $oldFace->getWidth(),
				'height' => $oldFace->getHeight(),
			]) + [
				'id' => $oldFace->getId(),
				'cluster' => $oldFace->getCluster(),
				'is_groupable' => $oldFace->getIsGroupable(),
			];
		}

		$new = [];
		foreach ($faces as $index => $face) {
			$new[$index] = ManualFaceDetector::edges([
				'x' => $face->getX(),
				'y' => $face->getY(),
				'width' => $face->getWidth(),
				'height' => $face->getHeight(),
			]);
		}

		$matches = FaceRect::matchClusters($new, $old);

		// A marking whose search found no face goes back into the clustering
		// when the analysis finds one, but not if the user ignored it: in its
		// hidden cluster it would be a sample, and faces would join it there.
		$ignored = [];
		foreach ($manualFaces as $manualFace) {
			if ($manualFace->getManualState() === Face::MANUAL_STATE_NO_FACE && !is_null($manualFace->getCluster())) {
				$ignored = $this->faceMapper->findIgnoredFaceIdsOfImage($image->getId());
				break;
			}
		}

		$insert = [];
		$overwrites = [];
		foreach ($faces as $index => $face) {
			$match = $matches[$index] ?? null;
			if (is_null($match)) {
				$insert[] = $face;
				continue;
			}

			$oldId = $old[$match['old']]['id'];
			if (isset($manualFaces[$oldId])) {
				if ($refined) {
					$state = $manualFaces[$oldId]->getManualState();
					$overwrites[$oldId] = [
						'face' => $face,
						'confirm' => !is_null($state),
						'regroup' => $state === Face::MANUAL_STATE_NO_FACE && !in_array($oldId, $ignored, true),
					];
				}
				continue;
			}

			if ($refined && !is_null($match['cluster'])) {
				$face->setCluster($match['cluster']);
				if (!$match['is_groupable']) {
					// The old face was a face the user detached: see inheritClusters().
					$face->setIsGroupable(false);
				}
			}
			$insert[] = $face;
		}

		$this->faceMapper->overwriteWithAnalysis($overwrites);
		if (count($overwrites) > 0) {
			$this->logInfo(ManualFaceDetector::LOG_PREFIX . 'Image ' . $image->getId() . ': the analysis found ' . count($overwrites) . ' face(s) put there by hand, and took them over');
		}

		return $insert;
	}

}

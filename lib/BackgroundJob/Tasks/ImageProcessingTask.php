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
		$images = $context->propertyBag['images'];
		foreach($images as $image) {
			yield;

			$startMillis = round(microtime(true) * 1000);

			try {
				// Get a image lock
				$lockKey = 'facerecognition/' . $image->getId();
				$lockType = ILockingProvider::LOCK_EXCLUSIVE;
				$this->lockingProvider->acquireLock($lockKey, $lockType);

				$dbImage = $this->imageMapper->find($image->getUser(), $image->getId());

				// An image is done when it was processed, and in the refinement
				// pass it also has to have been refined. The images that were
				// only analyzed in the fast pass have to be taken again.
				$alreadyDone = $refined ? $dbImage->getIsRefined() : $dbImage->getIsProcessed();
				if ($alreadyDone) {
					$this->logInfo('Faces found: 0. Image will be skipped since it was already processed.');
					// Release lock of file.
					$this->lockingProvider->releaseLock($lockKey, $lockType);
					continue;
				}


				// Get an temp Image to process this image.
				$tempImage = $this->getTempImage($image);

				if (is_null($tempImage)) {
					// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
					$this->settingsService->setNeedRemoveStaleImages(true, $image->user);
					$this->logInfo('File with ID ' . $image->file . ' doesn\'t exist anymore, skipping it');
					// Release lock of file.
					$this->lockingProvider->releaseLock($lockKey, $lockType);
					continue;
				}

				if ($tempImage->getSkipped() === true) {
					$this->logInfo('Faces found: 0 (image will be skipped because it is too small)');
					// Keep the faces that were already found, if any, and mark
					// the image as done for this pass.
					$this->imageMapper->imageProcessed($image, array(), 0, null, $refined, false);
					// Release lock of file.
					$this->lockingProvider->releaseLock($lockKey, $lockType);
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

				if ($refined) {
					// The new faces replace the fast-pass ones, but the faces
					// found again in the same place keep their cluster, and with
					// it the person the user gave the cluster.
					$this->inheritClusters($image, $faces);
				}

				// Save new faces fo database
				$endMillis = round(microtime(true) * 1000);
				$duration = (int) max($endMillis - $startMillis, 0);
				$this->imageMapper->imageProcessed($image, $faces, $duration, null, $refined);

				// Release lock of file.
				$this->lockingProvider->releaseLock($lockKey, $lockType);
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
			$faces[$newIndex]->setCluster($inheritance['cluster']);
			if (!$inheritance['is_groupable']) {
				// The old face was a face the user detached: the new one keeps
				// its cluster and stays non-groupable, so that the clustering
				// does not put it back where the user took it out of.
				$faces[$newIndex]->setIsGroupable(false);
			}
		}
	}

}

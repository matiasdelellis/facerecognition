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
use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Helper\TempImage;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Taks that get all images that are still not processed and processes them.
 * Processing image means that each image is prepared, faces extracted form it,
 * and for each found face - face descriptor is extracted.
 */
class ImageProcessingTask extends FaceRecognitionBackgroundTask {

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/** @var FileService */
	protected $fileService;

	/** @var SettingsService */
	protected $settingsService;

	/** @var ModelManager */
	protected $modelManager;

	/** @var IModel */
	private $model;

	/** @var int|null Maximum image area (cached, so it is not recalculated for each image) */
	private $maxImageAreaCached;

	/**
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FileService $fileService
	 * @param SettingsService $settingsService
	 * @param ModelManager $modelManager Model manager
	 */
	public function __construct(ImageMapper     $imageMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService,
	                            ModelManager    $modelManager)
	{
		parent::__construct();

		$this->imageMapper        = $imageMapper;
		$this->fileService        = $fileService;
		$this->settingsService    = $settingsService;
		$this->modelManager       = $modelManager;

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

		// Get current model.
		$this->model = $this->modelManager->getCurrentModel();

		// Open model.
		$this->model->open();

		$images = $context->propertyBag['images'];
		foreach($images as $image) {
			yield;

			$startMillis = round(microtime(true) * 1000);

			try {
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
					$this->imageMapper->imageProcessed($image, array(), 0);
					continue;
				}

				// Get faces in the temporary image
				$tempImagePath = $tempImage->getTempPath();
				$rawFaces = $this->model->detectFaces($tempImagePath);

				$this->logInfo('Faces found: ' . count($rawFaces));

				$faces = array();
				foreach ($rawFaces as $rawFace) {
					// Get landmarks of face from model
					$rawLandmarks = $this->model->detectLandmarks($tempImage->getTempPath(), $rawFace);
					// Get descriptor of face from model
					$descriptor = $this->model->computeDescriptor($tempImage->getTempPath(), $rawLandmarks);

					// Normalize face and landmarks from model to original size
					$normFace = $this->getNormalizedFace($rawFace, $tempImage->getRatio());
					$normLandmarks = $this->getNormalizedLandmarks($rawLandmarks['parts'], $tempImage->getRatio());

					// Convert from dictionary of faces to our Face Db Entity and put Landmarks and descriptor
					$face = Face::fromModel($image->getId(), $normFace);
					$face->landmarks = $normLandmarks;
					$face->descriptor = $descriptor;

					$faces[] = $face;
				}

				// Save new faces fo database
				$endMillis = round(microtime(true) * 1000);
				$duration = max($endMillis - $startMillis, 0);
				$this->imageMapper->imageProcessed($image, $faces, $duration);
			} catch (\Exception $e) {
				if ($e->getMessage() === "std::bad_alloc") {
					throw new \RuntimeException("Not enough memory to run face recognition! Please look FAQ at https://github.com/matiasdelellis/facerecognition/wiki/FAQ");
				}
				$this->logInfo('Faces found: 0. Image will be skipped because of the following error: ' . $e->getMessage());
				$this->logDebug($e);
				$this->imageMapper->imageProcessed($image, array(), 0, $e);
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

		$imagePath = $this->fileService->getLocalFile($file);

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

		// Get this setting on main app_config.
		// Note that this option has lower and upper limits and validations
		$this->maxImageAreaCached = $this->settingsService->getAnalysisImageArea();

		// Check if admin override it in config and it is valid value
		//
		$maxImageArea = $this->settingsService->getMaximumImageArea();
		if ($maxImageArea > 0) {
			$this->maxImageAreaCached = $maxImageArea;
		}
		// Also check if we are provided value from command line.
		//
		if ((array_key_exists('max_image_area', $this->context->propertyBag)) &&
		    (!is_null($this->context->propertyBag['max_image_area']))) {
			$this->maxImageAreaCached = $this->context->propertyBag['max_image_area'];
		}

		return $this->maxImageAreaCached;
	}

	/**
	 * Helper method, to normalize face sizes back to original dimensions, based on ratio
	 *
	 */
	private function getNormalizedFace(array $rawFace, float $ratio): array {
		$face = [];
		$face['left'] = intval(round(max($rawFace['left'], 0)*$ratio));
		$face['right'] = intval(round($rawFace['right']*$ratio));
		$face['top'] = intval(round(max($rawFace['top'], 0)*$ratio));
		$face['bottom'] = intval(round($rawFace['bottom']*$ratio));
		$face['detection_confidence'] = $rawFace['detection_confidence'];
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

}
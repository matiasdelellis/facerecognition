<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
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
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

/**
 * Plain old PHP object holding all information
 * that are needed to process all faces from one image
 */
class ImageProcessingContext {
	/** @var string Path to the image being processed */
	private $imagePath;

	/** @var string Path to temporary, resized image */
	private $tempPath;

	/** @var float Ratio of resized image, when scaling it */
	private $ratio;

	/** @var array<Face> All found faces in image */
	private $faces;

	public function __construct(string $imagePath, string $tempPath, float $ratio) {
		$this->imagePath = $imagePath;
		$this->tempPath = $tempPath;
		$this->ratio = $ratio;
	}

	public function getImagePath(): string {
		return $this->imagePath;
	}

	public function getTempPath(): string {
		return $this->tempPath;
	}

	public function getRatio(): float {
		return $this->ratio;
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
	public function setFaces($faces) {
		$this->faces = $faces;
	}
}

/**
 * Taks that get all images that are still not processed and processes them.
 * Processing image means that each image is prepared, faces extracted form it,
 * and for each found face - face descriptor is extracted.
 */
class ImageProcessingTask extends FaceRecognitionBackgroundTask {
	/** @var IConfig Config */
	private $config;

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/** @var ITempManager */
	private $tempManager;

	/**
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(IConfig $config, ImageMapper $imageMapper, ITempManager $tempManager) {
		parent::__construct();
		$this->config = $config;
		$this->imageMapper = $imageMapper;
		$this->tempManager = $tempManager;
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

		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));
		$requirements = new Requirements($context->appManager, $model);

		$dataDir = rtrim($context->config->getSystemValue('datadirectory', \OC::$SERVERROOT.'/data'), '/');
		$images = $context->propertyBag['images'];

		$cfd = new \CnnFaceDetection($requirements->getFaceDetectionModel());
		$fld = new \FaceLandmarkDetection($requirements->getLandmarksDetectionModel());
		$fr = new \FaceRecognition($requirements->getFaceRecognitionModel());

		foreach($images as $image) {
			yield;

			$imageProcessingContext = null;
			$startMillis = round(microtime(true) * 1000);
			try {
				$imageProcessingContext = $this->findFaces($cfd, $dataDir, $image);
				if ($imageProcessingContext === null) {
					// We didn't got exception, but null result means we should skip this image
					continue;
				}

				$this->populateDescriptors($fld, $fr, $imageProcessingContext);

				$endMillis = round(microtime(true) * 1000);
				$duration = max($endMillis - $startMillis, 0);
				$this->imageMapper->imageProcessed($image, $imageProcessingContext->getFaces(), $duration);
			} catch (\Exception $e) {
				$this->imageMapper->imageProcessed($image, array(), 0, $e);
			} finally {
				$this->tempManager->clean();
			}
		}

		return true;
	}

	/**
	 * Given an image, it finds all faces on it.
	 * If image should be skipped, returns null.
	 * If there is any error, throws exception
	 *
	 * @param \CnnFaceDetection $cfd Face detection model
	 * @param string $dataDir Directory where data is stored
	 * @param Image $image Image to find faces on
	 * @return ImageProcessingContext|null Generated context that hold all information needed later for this image
	 */
	private function findFaces(\CnnFaceDetection $cfd, string $dataDir, Image $image) {
		// todo: check if this hits I/O (database, disk...), consider having lazy caching to return user folder from user
		$userFolder = $this->context->rootFolder->getUserFolder($image->user);
		$userRoot = $userFolder->getParent();
		$file = $userRoot->getById($image->file);
		if (empty($file)) {
			$this->logInfo('File with ID ' . $image->file . ' doesn\'t exist anymore, skipping it');
			return null;
		}

		// todo: this concat is wrong with shared files.
		$imagePath = $dataDir . $file[0]->getPath();
		$this->logInfo('Processing image ' . $imagePath);
		$imageProcessingContext = $this->prepareImage($imagePath);
		if ($imageProcessingContext == null) {
			return null;
		}

		// Detect faces from model
		$facesFound = $cfd->detect($imageProcessingContext->getTempPath());

		// Convert from dictionary of faces to our Face Db Entity
		$faces = array();
		foreach ($facesFound as $faceFound) {
			$face = Face::fromModel($image->getId(), $faceFound);
			$face->normalizeSize($imageProcessingContext->getRatio());
			$faces[] = $face;
		}

		$imageProcessingContext->setFaces($faces);
		$this->logInfo('Faces found ' . count($faces));

		return $imageProcessingContext;
	}

	/**
	 * Given an image, it will rotate, scale and save image to temp location, ready to be consumed by pdlib.
	 *
	 * @param string $imagePath Path to image on disk
	 *
	 * @return ImageProcessingContext|null Generated context that hold all information needed later for this image
	 * or null if images should be skipped.
	 */
	private function prepareImage(string $imagePath) {
		$image = new OCP_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
		if (!$image->valid()) {
			throw new \RuntimeException("Image is not valid, probably cannot be loaded");
		}

		// Ignore processing of images that are not large enough.
		$minImageSize = intval($this->config->getAppValue('facerecognition', 'min_image_size', '512'));
		if ((imagesx($image->resource()) < $minImageSize) || (imagesy($image->resource()) < $minImageSize)) {
			return null;
		}

		// Based on amount on memory PHP have, we will determine maximum amount of image size that we need to scale to.
		// This reasoning and calculations are all based on analysis given here:
		// https://github.com/matiasdelellis/facerecognition/wiki/Performance-analysis-of-DLib%E2%80%99s-CNN-face-detection
		$allowedMemory = $this->context->propertyBag['memory'];
		$maxImageArea = intval((0.75 * $allowedMemory) / 1024); // in pixels^2
		$ratio = $this->resizeImage($image, $maxImageArea);

		$tempfile = $this->tempManager->getTemporaryFile(pathinfo($imagePath, PATHINFO_EXTENSION));
		$image->save($tempfile);
		return new ImageProcessingContext($imagePath, $tempfile, $ratio);
	}

	/**
	 * Resizes the image to reach max image area, but preserving ratio.
	 * Stolen and adopted from OC_Image->resize() (difference is that this returns ratio of resize.)
	 *
	 * @param OC_Image $image Image to resize
	 * @param int $maxImageArea The maximum size of image we can handle (in pixels^2).
	 *
	 * @return float Ratio of resize. 1 if there was no resize
	 */
	public function resizeImage(OCP_Image $image, int $maxImageArea): float {
		if (!$image->valid()) {
			$message = "Image is not valid, probably cannot be loaded";
			$this->logInfo($message);
			throw new \RuntimeException($message);
		}

		$widthOrig = imagesx($image->resource());
		$heightOrig = imagesy($image->resource());
		if (($widthOrig <= 0) || ($heightOrig <= 0)) {
			$message = "Image is having non-positive width or height, cannot continue";
			$this->logInfo($message);
			throw new \RuntimeException($message);
		}

		$areaRatio = $maxImageArea / ($widthOrig * $heightOrig);
		$scaleFactor = sqrt($areaRatio);

		$newWidth = intval(round($widthOrig * $scaleFactor));
		$newHeight = intval(round($heightOrig * $scaleFactor));

		$success = $image->preciseResize($newWidth, $newHeight);
		if ($success === false) {
			throw new \RuntimeException("Error during image resize");
		}

		$this->logDebug(sprintf('Image scaled from %dx%d to %dx%d (since max image area is %d pixels^2)',
			$newWidth, $newHeight, $widthOrig, $heightOrig, $maxImageArea));

		return 1 / $scaleFactor;
	}

	/**
	 * Gets all face descriptors in a given image processing context. Populates "descriptor" in array of faces.
	 *
	 * @param \FaceLandmarkDetection $fld Landmark detection model
	 * @param \FaceRecognition $fr Face recognition model
	 * @param ImageProcessingContext Image processing context
	 */
	private function populateDescriptors(\FaceLandmarkDetection $fld, \FaceRecognition $fr, ImageProcessingContext $imageProcessingContext) {
		$faces = $imageProcessingContext->getFaces();

		foreach($faces as &$face) {
			$tempfilePath = $this->cropFace($imageProcessingContext->getImagePath(), $face);

			// Usually, second argument to detect should be just $face. However, since we are doing image acrobatics
			// and already have cropped image, bounding box for landmark detection is now complete (cropped) image!
			$landmarks = $fld->detect($tempfilePath, array(
				"left" => 0, "top" => 0, "bottom" => $face->height(), "right" => $face->width()));
			$descriptor = $fr->computeDescriptor($tempfilePath, $landmarks);
			$face->descriptor = $descriptor;
		}
	}

	private function cropFace(string $imagePath, Face $face): string {
		// todo: we are loading same image two times, fix this
		$image = new OCP_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
		$success = $image->crop($face->left, $face->top, $face->width(), $face->height());
		if ($success === false) {
			throw new \RuntimeException("Error during image cropping");
		}

		$tempfile = $this->tempManager->getTemporaryFile(pathinfo($imagePath, PATHINFO_EXTENSION));
		$image->save($tempfile);
		return $tempfile;
	}
}
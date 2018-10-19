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

use OC_Image;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\Db\FaceNew;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

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

	/** @var array<FaceNew> All found faces in image */
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
	 * @return FaceNew[] Array of faces
	 */
	public function getFaces(): array {
		return $this->faces;
	}

	/**
	 * @param array<FaceNew> $faces Array of faces to set
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
	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/**
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(ImageMapper $imageMapper) {
		parent::__construct();
		$this->imageMapper = $imageMapper;
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
	public function do(FaceRecognitionContext $context) {
		$this->setContext($context);
		$dataDir = rtrim($context->config->getSystemValue('datadirectory', \OC::$SERVERROOT.'/data'), '/');
		$images = $context->propertyBag['images'];
		// todo: move to Requirements class?
		$cfd = new \CnnFaceDetection("mmod_human_face_detector.dat");
		$fld = new \FaceLandmarkDetection("shape_predictor_5_face_landmarks.dat");
		$fr = new \FaceRecognition("dlib_face_recognition_resnet_model_v1.dat");

		foreach($images as $image) {
			yield;

			$imageProcessingContext = null;
			$startMillis = round(microtime(true) * 1000);
			try {
				$imageProcessingContext = $this->findFaces($cfd, $dataDir, $image);
				if ($imageProcessingContext == null) {
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
				if (($imageProcessingContext != null) and ($imageProcessingContext->getTempPath())) {
					unlink($imageProcessingContext->getTempPath());
				}
			}
		}
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

		// Detect faces from model
		$facesFound = $cfd->detect($imageProcessingContext->getTempPath());

		// Convert from dictionary of faces to our Face Db Entity
		$faces = array();
		foreach ($facesFound as $faceFound) {
			$face = FaceNew::fromModel($image, $faceFound);
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
	 * @return ImageProcessingContext Generated context that hold all information needed later for this image
	 */
	private function prepareImage(string $imagePath): ImageProcessingContext {
		$image = new \OC_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
		if (!$image->valid()) {
			throw new \RuntimeException("Image is not valid, probably cannot be loaded");
		}

		// todo: be smarter with this 1024 constant. Depending on GPU/memory of the host, this can be larger.
		$ratio = $this->resizeImage($image, 1024);

		// todo: Although I worry more in terms of security. Copy all images in a public temporary directory - this could not even work,
		// since it is common that you do not have writing permissions in shared environments.
		// You can take ideas here:
		// https://github.com/nextcloud/server/blob/da6c2c9da1721de7aa05b15af1356e3511069980/lib/private/TempManager.php
		$tempfilePath = tempnam(sys_get_temp_dir(), "facerec_");
		$tempfilePathWithExtension = $tempfilePath . '.' . pathinfo($imagePath, PATHINFO_EXTENSION);
		rename($tempfilePath, $tempfilePathWithExtension);
		$image->save($tempfilePathWithExtension);
		return new ImageProcessingContext($imagePath, $tempfilePathWithExtension, $ratio);
	}

	/**
	 * Resizes the image preserving ratio. Stolen and adopted from OC_Image->resize().
	 * Difference is that this returns ratio of resize.
	 * Also, resize is not done if $maxSize is less than both width and height.
	 *
	 * @* @param OC_Image $image Image to resize
	 * @param int $maxSize The maximum size of either the width or height.
	 * @return float Ratio of resize. 1 if there was no resize
	 */
	public function resizeImage(OC_Image $image, int $maxSize): float {
		if (!$image->valid()) {
			$this->logInfo(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			throw new \RuntimeException("Image is not valid, probably cannot be loaded");
		}

		$widthOrig = imagesx($image->resource());
		$heightOrig = imagesy($image->resource());
		if (($widthOrig < $maxSize) && ($heightOrig < $maxSize)) {
			return 1.0;
		}

		$ratioOrig = $widthOrig / $heightOrig;

		if ($ratioOrig > 1) {
			$newHeight = round($maxSize / $ratioOrig);
			$newWidth = $maxSize;
		} else {
			$newWidth = round($maxSize * $ratioOrig);
			$newHeight = $maxSize;
		}

		$success = $image->preciseResize((int)round($newWidth), (int)round($newHeight));
		if ($success == false) {
			throw new \RuntimeException("Error during image resize");
		}

		return $widthOrig / $newWidth;
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
			try {
				// Usually, second argument to detect should be just $face. However, since we are doing image acrobatics
				// and already have cropped image, bounding box for landmark detection is now complete (cropped) image!
				$landmarks = $fld->detect($tempfilePath, array(
					"left" => 0, "top" => 0, "bottom" => $face->height(), "right" => $face->width()));
				$descriptor = $fr->computeDescriptor($tempfilePath, $landmarks);
				$face->descriptor = $descriptor;
			} finally {
				unlink($tempfilePath);
			}
		}
	}

	private function cropFace(string $imagePath, FaceNew $face): string {
		// todo: we are loading same image two times, fix this
		$image = new \OC_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
		$success = $image->crop($face->left, $face->top, $face->width(), $face->height());
		if ($success == false) {
			throw new \RuntimeException("Error during image cropping");
		}

		// todo: same worry about using public temp names as above
		$tempfilePath = tempnam(sys_get_temp_dir(), "facerec_");
		$tempfilePathWithExtension = $tempfilePath . '.' . pathinfo($imagePath, PATHINFO_EXTENSION);
		rename($tempfilePath, $tempfilePathWithExtension);
		$image->save($tempfilePathWithExtension);
		return $tempfilePathWithExtension;
	}
}
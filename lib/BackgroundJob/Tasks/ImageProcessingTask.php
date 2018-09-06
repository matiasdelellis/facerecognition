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
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

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

		// number of things that can go wrong below is too damn high:) be more defensive
		foreach($images as $image) {
			$startMillis = round(microtime(true) * 1000);
			// todo: check if this hits I/O (database, disk...), consider having lazy caching to return user folder from user
			$userFolder = $context->rootFolder->getUserFolder($image->user);
			$userRoot = $userFolder->getParent();
			$file = $userRoot->getById($image->file);
			$imagePath = $dataDir . $file[0]->getPath();
			$this->logInfo('Processing image ' . $imagePath);
			list($tempfilePath, $ratio) = $this->prepareImage($imagePath);

			$facesFound = $cfd->detect($tempfilePath);
			$this->logInfo('Faces found ' . count($facesFound));
			unlink($tempfilePath); // todo: make sure this is deleted, in finally block

			foreach($facesFound as &$faceFound) {
				// Normalize face back to original dimensions
				foreach(array("left", "right", "top", "bottom") as $side) {
					$faceFound[$side] = intval(round($faceFound[$side] * $ratio));
				}
				$tempfilePath = $this->cropFace($imagePath, $faceFound);
				// Usually, second argument to detect should be just $faceFound. However, since we are doing image acrobatics
				// and are cropping image now, bounding box for landmark detection is now complete (cropped) image!
				$landmarks = $fld->detect($tempfilePath, array(
					"left" => 0, "top" => 0,
					"bottom" => $faceFound["bottom"] - $faceFound["top"], "right" => $faceFound["right"] - $faceFound["left"]));
				$descriptor = $fr->computeDescriptor($tempfilePath, $landmarks);
				$faceFound["descriptor"] = $descriptor;
				unlink($tempfilePath); // todo: make sure this is deleted, in finally block
			}

			$endMillis = round(microtime(true) * 1000);
			$duration = max($endMillis - $startMillis, 0);
			// todo: insert in DB whether there is error or not, whatever we got up to that point
			$this->imageMapper->imageProcessed($image, $facesFound, $duration);
			yield;
		}
	}

	/**
	 * Given an image, it will rotate, scale and save image to temp location, ready to be consumed by pdlib.
	 *
	 * @param string $imagePath Path to image on disk
	 *
	 * @return array Tuple of 2 value. First is temporary location where prepared image is saved.
	 * Caller should delete this temp file. Second is ratio of scaled image in this process.
	 */
	private function prepareImage(string $imagePath):array {
		$image = new \OC_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
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
		return array($tempfilePathWithExtension, $ratio);
	}

	private function cropFace(string $imagePath, array $face): string {
		// todo: we are loading same image two times, fix this
		$image = new \OC_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
		$image->crop($face["left"], $face["top"], $face["right"] - $face["left"], $face["bottom"] - $face["top"]);
		// todo: same worry about using public temp names as above
		$tempfilePath = tempnam(sys_get_temp_dir(), "facerec_");
		$tempfilePathWithExtension = $tempfilePath . '.' . pathinfo($imagePath, PATHINFO_EXTENSION);
		rename($tempfilePath, $tempfilePathWithExtension);
		$image->save($tempfilePathWithExtension);
		return $tempfilePathWithExtension;
	}

	/**
	 * Resizes the image preserving ratio. Stolen and adopted from OC_Image->resize().
	 * Difference is that this returns ratio of resize.
	 * Also, resize is not done if $maxSize is less than both width and height.
	 *
	 * @* @param OC_Image $image Image to resize
	 * @param integer $maxSize The maximum size of either the width or height.
	 * @return double Ratio of resize. 1 if there was no resize
	 */
	public function resizeImage(OC_Image $image, int $maxSize) {
		if (!$image->valid()) {
			$this->logInfo(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			// todo: throw exception here
			return 1.0;
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

		$image->preciseResize((int)round($newWidth), (int)round($newHeight));
		return $widthOrig / $newWidth;
	}
}
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
use OCP\IDBConnection;
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
	/** @var IDBConnection DB connection */
	protected $connection;

	/**
	 * @param IDBConnection $connection DB connection
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(IDBConnection $connection) {
		parent::__construct();
		$this->connection = $connection;
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
		$cfd = new \CnnFaceDetection('mmod_human_face_detector.dat');

		// number of things that can go wrong below is too damn high:) be more defensive
		foreach($images as $image) {
			// todo: check if this hits I/O (database, disk...), consider having lazy caching to return user folder from user
			$userFolder = $context->rootFolder->getUserFolder($image->user);
			$userRoot = $userFolder->getParent();
			$file = $userRoot->getById($image->file);
			$imagePath = $dataDir . $file[0]->getPath();
			$this->logDebug('Processing image ' . $imagePath);
			list($tempfilePath, $ratio) = $this->prepareImage($imagePath);

			$facesFound = $cfd->detect($tempfilePath);
			$this->logInfo('Faces found ' . count($facesFound));
			unlink($tempfilePath); // todo: make sure this is deleted, in finally block

			foreach($facesFound as $faceFound) {
				// todo: extract face to new image
				// todo: run face descriptor on newly obtained image
			}
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
	private function prepareImage($imagePath) {
		$image = new \OC_Image(null, $this->context->logger->getLogger(), $this->context->config);
		$image->loadFromFile($imagePath);
		$image->fixOrientation();
		// todo: be smarter with this 1024 constant. Depending on GPU/memory of the host, this can be larger.
		$ratio = $this->resizeImage($image, 1024);
		$tempfilePath = tempnam(sys_get_temp_dir(), "facerec_");
		$tempfilePathWithExtension = $tempfilePath . '.' . pathinfo($imagePath, PATHINFO_EXTENSION);
		rename($tempfilePath, $tempfilePathWithExtension);
		$image->save($tempfilePathWithExtension);
		return array($tempfilePathWithExtension, $ratio);
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
		return $ratioOrig;
	}
}
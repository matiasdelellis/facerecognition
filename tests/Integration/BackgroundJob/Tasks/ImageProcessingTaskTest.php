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
namespace OCA\FaceRecognition\Tests\Integration\BackgroundJob\Tasks;

use OCP\IUser;
use OCA\FaceRecognition\Tests\Integration\IntegrationTestCase;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Model\ModelManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ImageProcessingTask::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask::class)]
#[UsesClass(\OCA\FaceRecognition\Model\DlibCnnHogModel\DlibCnnHogModel::class)]
#[UsesClass(\OCA\FaceRecognition\Model\DlibCnnModel\DlibCnnModel::class)]
#[UsesClass(\OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel::class)]
#[UsesClass(\OCA\FaceRecognition\Model\ExternalModel\ExternalModel::class)]
#[UsesClass(\OCA\FaceRecognition\Model\ModelManager::class)]
#[UsesClass(\OCA\FaceRecognition\Service\DownloadService::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FileService::class)]
#[UsesClass(\OCA\FaceRecognition\Service\ModelService::class)]
#[UsesClass(\OCA\FaceRecognition\Service\SettingsService::class)]
#[UsesClass(\OCA\FaceRecognition\Helper\Imaginary::class)]
#[UsesClass(\OCA\FaceRecognition\Helper\TempImage::class)]
#[UsesClass(\OCA\FaceRecognition\Db\FaceMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ImageMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Image::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Face::class)]
class ImageProcessingTaskTest extends IntegrationTestCase {
	private $originalMinImageSize;
	private $originalMaxImageArea;
	public function setUp(): void {
		parent::setUp();

		// Since test is changing this values, try to preserve old values (this is best effort)
		$this->originalMinImageSize = intval(self::$appConfig->getValueInt('facerecognition', 'min_image_size', 512));
		$this->originalMaxImageArea = intval(self::$appConfig->getValueInt('facerecognition', 'max_image_area', 0));
		self::$appConfig->setValueInt('facerecognition', 'min_image_size', 1);
		self::$appConfig->setValueInt('facerecognition', 'max_image_area', 200 * 200);

		// Install models needed to test
		$model =self::$container->get('OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model');
		$model->install();

	}

	public function tearDown(): void {
		self::$appConfig->setValueInt('facerecognition', 'min_image_size', $this->originalMinImageSize);
		self::$appConfig->setValueInt('facerecognition', 'max_image_area', $this->originalMaxImageArea);

		parent::tearDown();
	}

	/**
	 * Tests when image cannot be loaded at all
	 * (tests whether image is declared as processed and error is added to it)
	 */
	public function testInvalidImage() {
		$image = $this->genericTestImageProcessing('bogus image data', true, 0);
		// Invalid image should have 0 as processing duration
		$this->assertEquals(0, $image->getProcessingDuration());
	}

	/**
	 * Tests that small images are skipped during processing
	 */
	public function testImageTooSmallToProcess() {
		self::$appConfig->setValueInt('facerecognition', 'min_image_size', 10000);
		$imgData = file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg');
		$image = $this->genericTestImageProcessing($imgData, false, 0);
	}

	/**
	 * Test when there is no faces on image
	 * (image should be declared as processed, but 0 faces should be associated with it)
	 */
	public function testNoFacesFound() {
		$imgData = file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/black.jpg');
		$image = $this->genericTestImageProcessing($imgData, false, 0);
	}

	/**
	 * Regular positive test that find one face in image
	 */
	public function testFindFace() {
		$imgData = file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg');
		$image = $this->genericTestImageProcessing($imgData, false, 1);

		// Check exact values for face boundaries (might need to update when we bump dlib/pdlib versions)
		$face = self::$faceMapper->getFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)[0];
		$this->assertNotNull($face);
		$this->assertEquals(49, $face->getX());
		$this->assertEquals(62, $face->getY());
		$this->assertEquals(75, $face->getWidth());
		$this->assertEquals(75, $face->getHeight());
	}

	/**
	 * Helper function that asserts in generic fashion whatever necessary.
	 *
	 * @param string|resource $imgData Image data that will be analyzed
	 * @param bool $expectingError True if we should assert that error is found, false if we should assert there is no error
	 * @param int $expectedFacesCount Number of faces that we should assert that should be found in processed image
	 *
	 * @return Image One found image
	 */
	private function genericTestImageProcessing($imgData, $expectingError, $expectedFacesCount) {

		$this->doImageProcessing($imgData);

		// Check that there is no unprocessed images
		$this->assertEquals(0, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));

		// Check image fields after processing
		$images = self::$imageMapper->findImages(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($images));
		$image = self::$imageMapper->find(self::$user->getUID(), $images[0]->getId());
		$this->assertTrue(is_null($image->getError()) xor $expectingError);
		$this->assertTrue($image->getIsProcessed());
		$this->assertNotNull(0, $image->getProcessingDuration());
		$this->assertNotNull($image->getLastProcessedTime());

		// Check number of found faces
		$this->assertEquals($expectedFacesCount, count(self::$faceMapper->getFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));

		return $image;
	}

	/**
	 * Helper method to set up and do image processing
	 *
	 * @param string|resource $imgData Image data that will be analyzed
	 * @param IUser|null $contextUser Optional user to process images for.
	 * If not given, images for all users will be processed.
	 */
	private function doImageProcessing($imgData,?IUser  $contextUser = null) {
		// Create ImageProcessingTask
		$modelManager =self::$container->get('OCA\FaceRecognition\Model\ModelManager');
		$lockingProvider =self::$container->get('OCP\Lock\ILockingProvider');
		$imageProcessingTask = new ImageProcessingTask(self::$imageMapper, self::$fileService, self::$settingsService, $modelManager, $lockingProvider);
		$this->assertNotEquals("", $imageProcessingTask->description());

		// Set user for which to do processing, if any
		self::$context->user = $contextUser;
		// Upload file
		self::$view->mkdir('files');
		self::$view->file_put_contents("files/foo1.jpg", $imgData);
		// Scan it, so it is in database, ready to be processed
		$this->doMissingImageScan(self::$user);
		self::$context->propertyBag['images'] = self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count(self::$context->propertyBag['images']));

		// Since this task returns generator, iterate until it is done
		$generator = $imageProcessingTask->execute(self::$context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}

	/**
	 * Helper method to set up and do scanning
	 *
	 * @param IUser|null $contextUser Optional user to scan for. If not given, images for all users will be scanned.
	 */
	private function doMissingImageScan(?IUser $contextUser = null) {
		// Reset config that full scan is done, to make sure we are scanning again
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');

		$addMissingImagesTask = new AddMissingImagesTask(self::$imageMapper, self::$fileService, self::$settingsService);

		// Set user for which to do scanning, if any
		self::$context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = $addMissingImagesTask->execute(self::$context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}

}
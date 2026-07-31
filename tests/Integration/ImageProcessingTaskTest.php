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
namespace OCA\FaceRecognition\Tests\Integration;

use OC;
use OC\Files\View;

use OCP\IConfig;
use OCP\IUser;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Model\ModelManager;

use Test\TestCase;

/**
 * @group DB
 */
class ImageProcessingTaskTest extends IntegrationTestCase {

	public function setUp(): void {
		parent::setUp();

		// Since test is changing this values, try to preserve old values (this is best effort)
		$this->originalMinImageSize = intval($this->config->getAppValue('facerecognition', 'min_image_size', '512'));
		$this->originalMaxImageArea = intval($this->config->getAppValue('facerecognition', 'max_image_area', 0));
		$this->config->setAppValue('facerecognition', 'min_image_size', 1);
		$this->config->setAppValue('facerecognition', 'max_image_area', 200 * 200);

		// Install models needed to test
		$model = $this->container->query('OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model');
		$model->install();

	}

	public function tearDown(): void {
		$this->config->setAppValue('facerecognition', 'min_image_size', $this->originalMinImageSize);
		$this->config->setAppValue('facerecognition', 'max_image_area', $this->originalMaxImageArea);

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
		$this->config->setAppValue('facerecognition', 'min_image_size', 10000);
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
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$face = $faceMapper->getFaces($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)[0];
		$face = $faceMapper->find($face->getId());
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
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		$this->doImageProcessing($imgData);

		// Check that there is no unprocessed images
		$this->assertEquals(0, count($imageMapper->findImagesWithoutFaces($this->user, ModelManager::DEFAULT_FACE_MODEL_ID)));

		// Check image fields after processing
		$images = $imageMapper->findImages($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($images));
		$image = $imageMapper->find($this->user->getUID(), $images[0]->getId());
		$this->assertTrue(is_null($image->getError()) xor $expectingError);
		$this->assertTrue($image->getIsProcessed());
		$this->assertNotNull(0, $image->getProcessingDuration());
		$this->assertNotNull($image->getLastProcessedTime());

		// Check number of found faces
		$this->assertEquals($expectedFacesCount, count($faceMapper->getFaces($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));

		return $image;
	}

	/**
	 * Helper method to set up and do image processing
	 *
	 * @param string|resource $imgData Image data that will be analyzed
	 * @param IUser|null $contextUser Optional user to process images for.
	 * If not given, images for all users will be processed.
	 */
	private function doImageProcessing($imgData, $contextUser = null) {
		// Create ImageProcessingTask
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');
		$modelManager = $this->container->query('OCA\FaceRecognition\Model\ModelManager');
		$lockingProvider = $this->container->query('OCP\Lock\ILockingProvider');
		$imageProcessingTask = new ImageProcessingTask($imageMapper, $fileService, $settingsService, $modelManager, $lockingProvider);
		$this->assertNotEquals("", $imageProcessingTask->description());

		// Set user for which to do processing, if any
		$this->context->user = $contextUser;
		// Upload file
		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		$view->file_put_contents("foo1.jpg", $imgData);
		// Scan it, so it is in database, ready to be processed
		$this->doMissingImageScan($this->user);
		$this->context->propertyBag['images'] = $imageMapper->findImagesWithoutFaces($this->user, ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($this->context->propertyBag['images']));

		// Since this task returns generator, iterate until it is done
		$generator = $imageProcessingTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}

	/**
	 * Helper method to set up and do scanning
	 *
	 * @param IUser|null $contextUser Optional user to scan for. If not given, images for all users will be scanned.
	 */
	private function doMissingImageScan($contextUser = null) {
		// Reset config that full scan is done, to make sure we are scanning again
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');

		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');
		$addMissingImagesTask = new AddMissingImagesTask($imageMapper, $fileService, $settingsService);

		// Set user for which to do scanning, if any
		$this->context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = $addMissingImagesTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}

}
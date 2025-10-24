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

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\ModelManager;
use OCA\FaceRecognition\Tests\Integration\IntegrationTestCase;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(AddMissingImagesTask::class)]
#[UsesClass(SettingsService::class)]
#[UsesClass(FaceRecognitionContext::class)]
#[UsesClass(FaceRecognitionLogger::class)]
#[UsesClass(\OCA\FaceRecognition\Db\FaceMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ImageMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ClusterMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Image::class)]
#[UsesClass(\OCA\FaceRecognition\Listener\UserDeletedListener::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FaceManagementService::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FileService::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask::class)]
class AddMissingImagesTaskTest extends IntegrationTestCase {

	public function setup(): void {
        parent::setUp();
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
	}
	/**
	 * Test that AddMissingImagesTask is updating app config that it finished full scan.
	 * Note that, in this test, we cannot check number of newly found images,
	 * as this instance might be in use and can lead to wrong results
	 */
	public function testFinishedFullScan() {
		$this->doMissingImageScan();

		$fullImageScanDone = self::$config->getUserValue(self::$user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$this->assertEquals('true', $fullImageScanDone);
	}

	/**
	 * Test that, after one scan is done, next scan will not find any new images
	 */
	public function testNewScanIsEmpty() {
		// Do it once, to make sure all images are inserted
		$this->doMissingImageScan();
		$fullImageScanDone = self::$config->getUserValue(self::$user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$this->assertEquals('true', $fullImageScanDone);

		// Second time, there should be no newly inserted images
		$this->doMissingImageScan();

		$this->assertEquals(0, self::$context->propertyBag['AddMissingImagesTask_insertedImages']);
		$this->assertEquals(0, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));
	}

	/**
	 * Test that empty crawling will do nothing
	 */
	public function testCrawlNoImages() {
		self::$view->mkdir('files');
		self::$view->file_put_contents("files/foo.txt", "content");

		$this->doMissingImageScan(self::$user);

		$this->assertEquals(0, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));
		$this->assertEquals(0, self::$context->propertyBag['AddMissingImagesTask_insertedImages']);
	}

	/**
	 * Test that crawling with some images will actually find them and add them to database
	 */
	public function testCrawl() {
		self::$view->mkdir('files');
		self::$view->file_put_contents("files/foo1.txt", "content");
		self::$view->file_put_contents("files/foo2.jpg", "content");
		self::$view->file_put_contents("files/foo3.png", "content");
		self::$view->mkdir('files/dir');
		self::$view->file_put_contents("files/dir/foo4.txt", "content");
		self::$view->file_put_contents("files/dir/foo5.bmp", "content");
		self::$view->file_put_contents("files/dir/foo6.png", "content");
		self::$view->mkdir('files/dir_nomedia');
		self::$view->file_put_contents("files/dir_nomedia/.nomedia", "content");
		self::$view->file_put_contents("files/dir_nomedia/foo7.jpg", "content");

		$this->doMissingImageScan(self::$user);

		// We should find 3 images only - foo2.jpg, foo3.png and dir/foo6.png. BMP mimetype (foo5.bmp) is not enabled by default.
		$this->assertEquals(3, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));
		$this->assertEquals(3, self::$context->propertyBag['AddMissingImagesTask_insertedImages']);
	}

	/**
	 * Helper method to set up and do scanning
	 *
	 * @param IUser|null $contextUser Optional user to scan for. If not given, images for all users will be scanned.
	 */
	private function doMissingImageScan(?IUser $contextUser = null) {
		// Reset config that full scan is done, to make sure we are scanning again
		$this->assertNotEquals("", self::$addMissingImagesTask->description());

		// Set user for which to do scanning, if any
		self::$context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = self::$addMissingImagesTask->execute(self::$context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}

}
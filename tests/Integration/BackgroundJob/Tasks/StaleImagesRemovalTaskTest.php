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

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Tests\Integration\IntegrationTestCase;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\StaleImagesRemovalTask;
use OCA\FaceRecognition\Model\ModelManager;
use OCA\FaceRecognition\Service\SettingsService;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(StaleImagesRemovalTask::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask::class)]
#[UsesClass(\OCA\FaceRecognition\Db\FaceMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Image::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ImageMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ClusterMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FileService::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask::class)]
#[UsesClass(\OCA\FaceRecognition\Listener\UserDeletedListener::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FaceManagementService::class)]
#[UsesClass(\OCA\FaceRecognition\Service\SettingsService::class)]
class StaleImagesRemovalTaskTest extends IntegrationTestCase {

	/**
	 * Test that StaleImagesRemovalTask is not active, even though there should be some removals.
	 */
	public function testNotNeededScan() {
		$image = new Image();
		$image->setUser(self::$user->getUid());
		$image->setFile(1);
		$image->setModel(ModelManager::DEFAULT_FACE_MODEL_ID);
		self::$imageMapper->insert($image);

		$generator = self::$staleImagesRemovalTask->execute(self::$context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());

		$this->assertEquals(0, self::$context->propertyBag['StaleImagesRemovalTask_staleRemovedImages']);
		self::$imageMapper->delete($image);
	}

	/**
	 * Test that image which exists only in database is removed when StaleImagesRemovalTask is run.
	 */
	public function testMissingImageRemoval() {
		$image = new Image();
		$image->setUser(self::$user->getUid());
		$image->setFile(2);
		$image->setModel(ModelManager::DEFAULT_FACE_MODEL_ID);
		self::$imageMapper->insert($image);

		$this->doStaleImagesRemoval();
		$this->assertEquals(1, self::$context->propertyBag['StaleImagesRemovalTask_staleRemovedImages']);
	}

	/**
	 * Test that image under .nomedia directory is removed
	 */
	public function testNoMediaImageRemoval() {
		// Create foo1.jpg in root and foo2.jpg in child directory
		self::$view->mkdir('files');
		self::$view->file_put_contents("files/foo1.jpg", "content");
		self::$view->mkdir('files/dir_nomedia');
		self::$view->file_put_contents("files/dir_nomedia/foo2.jpg", "content");
		// Create these two images in database by calling add missing images task
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$addMissingImagesTask = new AddMissingImagesTask(self::$imageMapper, self::$fileService, self::$settingsService);
		self::$context->user = self::$user;
		$generator = $addMissingImagesTask->execute(self::$context);
		foreach ($generator as $_) {
		}
		// TODO: add faces and person for those images, so we can exercise person
		// invalidation and face removal when image is removed.

		// We should find 2 images now - foo1.jpg, foo2.png
		$this->assertEquals(2, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));

		// We should not delete anything this time
		$this->doStaleImagesRemoval();
		$this->assertEquals(0, self::$context->propertyBag['StaleImagesRemovalTask_staleRemovedImages']);

		// Now add .nomedia file in subdirectory and one image (foo2.jpg) should be gone now
		self::$view->file_put_contents("files/dir_nomedia/.nomedia", "content");
		$this->doStaleImagesRemoval();
		$this->assertEquals(1, self::$context->propertyBag['StaleImagesRemovalTask_staleRemovedImages']);
		$this->assertEquals(1, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));
	}

	/**
	 * Helper method to set up and do scanning
	 *
	 * @param IUser|null $contextUser Optional user to scan for.
	 * If not given, stale images for all users will be renived.
	 */
	private function doStaleImagesRemoval(?IUser $contextUser = null) {
		// Set config that stale image removal is needed
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', SettingsService::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'true');

		$this->assertNotEquals("", self::$staleImagesRemovalTask->description());

		// Set user for which to do scanning, if any
		self::$context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = self::$staleImagesRemovalTask->execute(self::$context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}
}
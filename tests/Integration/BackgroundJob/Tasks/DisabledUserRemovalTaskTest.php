<?php
/**
 * @copyright Copyright (c) 2019, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018-2019, Branko Kokanovic <branko@kokanovic.org>
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
use OCA\FaceRecognition\BackgroundJob\Tasks\DisabledUserRemovalTask;

use OCA\FaceRecognition\Model\ModelManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(DisabledUserRemovalTask::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask::class)]
#[UsesClass(\OCA\FaceRecognition\Db\FaceMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Image::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ImageMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FileService::class)]
#[UsesClass(\OCA\FaceRecognition\Service\SettingsService::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ClusterMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Service\FaceManagementService::class)]
class DisabledUserRemovalTaskTest extends IntegrationTestCase {

	/** @var DisabledUserRemovalTask test instance*/
	protected static $disabledUserRemovalTask;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$disabledUserRemovalTask = new DisabledUserRemovalTask(self::$imageMapper, self::$faceMgmtService, self::$settingsService);
	}
	/**
	 * Test that check when user disable analysis.
	 */
	public function testNoMediaImageRemoval() {
		// Enables the analysis for the user to add images.
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', 'enabled', 'true');

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

		// Disable analysis for user
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', 'enabled', 'false');

		// Perform the removal due user disabling action.
		$this->doDisabledUserRemoval();

		// Now it must be empty
		$this->assertEquals(0, count(self::$imageMapper->findImagesWithoutFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID)));
	}

	/**
	 * Helper method to set up and do removal task.
	 *
	 * @param IUser|null $contextUser Optional user to scan for.
	 * If not given, stale images for all users will be renived.
	 */
	private function doDisabledUserRemoval(?IUser $contextUser = null) {
		$this->assertNotEquals("", self::$disabledUserRemovalTask->description());

		// Set user for which to do scanning, if any
		self::$context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = self::$disabledUserRemovalTask->execute(self::$context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}
}
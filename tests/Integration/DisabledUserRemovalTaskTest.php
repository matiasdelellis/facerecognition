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
use OCA\FaceRecognition\BackgroundJob\Tasks\DisabledUserRemovalTask;

use OCA\FaceRecognition\Db\Image;

use OCA\FaceRecognition\Model\ModelManager;

use Test\TestCase;

/**
 * @group DB
 */
class DisabledUserRemovalTaskTest extends IntegrationTestCase {

	/**
	 * Test that check when user disable analysis.
	 */
	public function testNoMediaImageRemoval() {
		// Enables the analysis for the user to add images.
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', 'enabled', 'true');

		// Create foo1.jpg in root and foo2.jpg in child directory
		$view = new View('/' . $this->user->getUID() . '/files');
		$view->file_put_contents("foo1.jpg", "content");
		$view->mkdir('dir_nomedia');
		$view->file_put_contents("dir_nomedia/foo2.jpg", "content");

		// Create these two images in database by calling add missing images task
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');
		$addMissingImagesTask = new AddMissingImagesTask($imageMapper, $fileService, $settingsService);
		$this->context->user = $this->user;
		$generator = $addMissingImagesTask->execute($this->context);
		foreach ($generator as $_) {
		}

		// TODO: add faces and person for those images, so we can exercise person
		// invalidation and face removal when image is removed.

		// We should find 2 images now - foo1.jpg, foo2.png
		$this->assertEquals(2, count($imageMapper->findImagesWithoutFaces($this->user, ModelManager::DEFAULT_FACE_MODEL_ID)));

		// Disable analysis for user
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', 'enabled', 'false');

		// Perform the removal due user disabling action.
		$this->doDisabledUserRemoval();

		// Now it must be empty
		$this->assertEquals(0, count($imageMapper->findImagesWithoutFaces($this->user, ModelManager::DEFAULT_FACE_MODEL_ID)));
	}

	/**
	 * Helper method to set up and do removal task.
	 *
	 * @param IUser|null $contextUser Optional user to scan for.
	 * If not given, stale images for all users will be renived.
	 */
	private function doDisabledUserRemoval($contextUser = null) {
		$disabledUserRemovalTask = $this->createDisabledUserRemovalTask();
		$this->assertNotEquals("", $disabledUserRemovalTask->description());

		// Set user for which to do scanning, if any
		$this->context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = $disabledUserRemovalTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}

	private function createDisabledUserRemovalTask() {
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$faceMgmtService = $this->container->query('OCA\FaceRecognition\Service\FaceManagementService');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');
		return new DisabledUserRemovalTask($imageMapper, $faceMgmtService, $settingsService);
	}
}
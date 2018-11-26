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

use Test\TestCase;

class AddMissingImagesTaskTest extends TestCase {
	/** @var IAppContainer */
	private $container;

	/** @var FaceRecognitionContext Context */
	private $context;

	/** @var IUser User */
	private $user;

	/** @var IConfig Config */
	private $config;

	public function setUp() {
		parent::setUp();
		// Better safe than sorry. Warn user that database will be changed in chaotic manner:)
		if (false === getenv('TRAVIS')) {
			$this->fail("This test touches database. Add \"TRAVIS\" env variable if you want to run these test on your local instance.");
		}

		// Create user on which we will upload images and do testing
		$userManager = OC::$server->getUserManager();
		$this->user = $userManager->createUser('testuser' . rand(0, PHP_INT_MAX), 'password');

		// Get container to get classes using DI
		$app = new App('facerecognition');
		$this->container = $app->getContainer();

		// Insantiate our context, that all tasks need
		$appManager = $this->container->query('OCP\App\IAppManager');
		$userManager = $this->container->query('OCP\IUserManager');
		$rootFolder = $this->container->query('OCP\Files\IRootFolder');
		$this->config = $this->container->query('OCP\IConfig');
		$logger = $this->container->query('OCP\ILogger');
		$this->context = new FaceRecognitionContext($appManager, $userManager, $rootFolder, $this->config);
		$this->context->logger = new FaceRecognitionLogger($logger);
	}

	public function tearDown() {
		// TODO: Tracked with #32 - once we implement callback on user deletion,
		// we need another test that drops images from database.
		$this->user->delete();
		parent::tearDown();
	}

	/**
	 * Test that AddMissingImagesTask is updating app config that it finished full scan.
	 * Note that, in this test, we cannot check number of newly found images,
	 * as this instance might be in use and can lead to wrong results
	 */
	public function testFinishedFullScan() {
		$this->doMissingImageScan();

		$fullImageScanDone = $this->config->getAppValue('facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$this->assertEquals('true', $fullImageScanDone);
	}

	/**
	 * Test that, after one scan is done, next scan will not find any new images
	 */
	public function testNewScanIsEmpty() {
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');

		// Do it once, to make sure all images are inserted
		$this->doMissingImageScan();
		$fullImageScanDone = $this->config->getAppValue('facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$this->assertEquals('true', $fullImageScanDone);

		// Second time, there should be no newly inserted images
		$this->doMissingImageScan();

		$this->assertEquals(0, $this->context->propertyBag['AddMissingImagesTask_insertedImages']);
		$this->assertEquals(0, count($imageMapper->findImagesWithoutFaces($this->user)));
	}

	/**
	 * Test that empty crawling will do nothing
	 */
	public function testCrawlNoImages() {
		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		$view->file_put_contents("foo.txt", "content");

		$this->doMissingImageScan($this->user);

		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$this->assertEquals(0, count($imageMapper->findImagesWithoutFaces($this->user)));
		$this->assertEquals(0, $this->context->propertyBag['AddMissingImagesTask_insertedImages']);
	}

	/**
	 * Test that crawling with some images will actually find them and add them to database
	 */
	public function testCrawl() {
		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		$view->file_put_contents("foo1.txt", "content");
		$view->file_put_contents("foo2.jpg", "content");
		$view->file_put_contents("foo3.png", "content");
		$view->mkdir('dir');
		$view->file_put_contents("dir/foo4.txt", "content");
		$view->file_put_contents("dir/foo5.bmp", "content");
		$view->mkdir('dir_nomedia');
		$view->file_put_contents("dir_nomedia/.nomedia", "content");
		$view->file_put_contents("dir_nomedia/foo7.jpg", "content");

		$this->doMissingImageScan($this->user);

		// We should find 3 images only - foo2.jpg, foo3.png and dir/foo5.bmp
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$this->assertEquals(3, count($imageMapper->findImagesWithoutFaces($this->user)));
		$this->assertEquals(3, $this->context->propertyBag['AddMissingImagesTask_insertedImages']);
	}

	/**
	 * Helper method to set up and do scanning
	 *
	 * @* @param IUser|null $user Optional user to scan for. If not given, images for all users will be scanned.
	 */
	private function doMissingImageScan($user = null) {
		// Reset config that full scan is done, to make sure we are scanning again
		$this->config->setAppValue('facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');

		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$addMissingImagesTask = new AddMissingImagesTask($this->config, $imageMapper);

		// Set user for which to do scanning, if any
		$this->context->user = $user;

		// Since this task returns generator, iterate until it is done
		$generator = $addMissingImagesTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}
}
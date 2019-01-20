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

use OCA\FaceRecognition\FaceManagementService;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

use Test\TestCase;

class ResetAllTest extends TestCase {
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
		$username = 'testuser' . rand(0, PHP_INT_MAX);
		$this->user = $userManager->createUser($username, 'password');
		$this->loginAsUser($username);
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
		$this->user->delete();
		parent::tearDown();
	}

	/**
	 * Test that AddMissingImagesTask is updating app config that it finished full scan.
	 * Note that, in this test, we cannot check number of newly found images,
	 * as this instance might be in use and can lead to wrong results
	 */
	public function testResetAll() {
		// Add one image to DB
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$image = new Image();
		$image->setUser($this->user->getUid());
		$image->setFile(1);
		$image->setModel(AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$imageMapper->insert($image);
		$imageCount = $imageMapper->countUserImages($this->user->getUID(), AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $imageCount);

		// Add one face to DB
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$face = Face::fromModel($image->getId(), array("left"=>0, "right"=>100, "top"=>0, "bottom"=>100));
		$faceMapper->insert($face);
		$faceCount = $faceMapper->countFaces($this->user->getUID(), AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $faceCount);

		// Add one person to DB
		$personMapper = $this->container->query('OCA\FaceRecognition\Db\PersonMapper');
		$person = new Person();
		$person->setUser($this->user->getUID());
		$person->setName('foo');
		$person->setIsValid(true);
		$personMapper->insert($person);
		$personCount = $personMapper->countPersons($this->user->getUID());
		$this->assertEquals(1, $personCount);

		// Execute reset all
		$userManager = $this->container->query('OCP\IUserManager');
		$faceMgmtService = new FaceManagementService($this->config, $userManager, $faceMapper, $imageMapper, $personMapper);
		$faceMgmtService->resetAllForUser($this->user->getUID());

		// Check that everything is gone
		$imageCount = $imageMapper->countUserImages($this->user->getUID(), AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $imageCount);
		$faceCount = $faceMapper->countFaces($this->user->getUID(), AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $faceCount);
		$personCount = $personMapper->countPersons($this->user->getUID());
		$this->assertEquals(0, $personCount);
	}
}
<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2019, Branko Kokanovic <branko@kokanovic.org>
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

use OCP\IConfig;
use OCP\IUser;
use OCP\AppFramework\App;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

use Test\TestCase;

class CreateClustersTaskTest extends TestCase {
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
		$faceMgmtService = $this->container->query('OCA\FaceRecognition\FaceManagementService');
		$faceMgmtService->resetAllForUser($this->user->getUID());

		$this->user->delete();

		parent::tearDown();
	}

	/**
	 * Test that one face that was not in any cluster will be assigned new person
	 */
	public function testCreateClustersSimple() {
		$personMapper = $this->container->query('OCA\FaceRecognition\Db\PersonMapper');
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		$image = new Image();
		$image->setUser($this->user->getUid());
		$image->setFile(1);
		$image->setModel(AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$imageMapper->insert($image);

		$face = Face::fromModel($image->getId(), array("left"=>0, "right"=>100, "top"=>0, "bottom"=>100));
		$faceMapper->insertFace($face);

		$this->doCreateClustersTask($personMapper, $imageMapper, $faceMapper, $this->user);

		$personCount = $personMapper->countPersons($this->user->getUID());
		$this->assertEquals(1, $personCount);
		$persons = $personMapper->findAll($this->user->getUID());
		$this->assertEquals(1, count($persons));
		$personId = $persons[0]->getId();

		$faceCount = $faceMapper->countFaces($this->user->getUID(), AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $faceCount);
		$faces = $faceMapper->getFaces($this->user->getUID(), AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertNotNull($faces[0]->getPerson());
		$faces = $faceMapper->findFacesFromPerson($this->user->getUID(), $personId, AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($faces));
	}

	/**
	 * Helper method to set up and do create clusters task
	 *
	 * @param IUser|null $contextUser Optional user to create clusters for.
	 * If not given, clusters for all users will be processed.
	 */
	private function doCreateClustersTask($personMapper, $imageMapper, $faceMapper, $contextUser = null) {
		if ($contextUser) {
			$this->config->setUserValue($contextUser->getUID(), 'facerecognition', 'force-create-clusters', 'true');
		}

		$createClustersTask = new CreateClustersTask($this->config, $personMapper, $imageMapper, $faceMapper);
		$this->assertNotEquals("", $createClustersTask->description());

		// Set user for which to do processing, if any
		$this->context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = $createClustersTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}
}
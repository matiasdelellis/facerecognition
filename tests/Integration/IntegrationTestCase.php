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
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\Model\ModelManager;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\FaceManagementService;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCP\IDBConnection;

use \phpunit\Framework\TestCase;

/**
 * Main class that all integration tests should inherit from.
 */
abstract class IntegrationTestCase extends TestCase {

	/** @var int*/
	protected static $originalModel = 0;

	/** @var IConfig Config */
	protected static $config;

	/** @var IAppConfig Config */
	protected static $appConfig;

	/** @var IAppContainer */
	protected static $container;

	/** @var IUserManager */
	protected static $userManager;

	/** @var IUser User */
	protected static $user;

	/** @var SettingsService*/
	protected static $settingsService;

	/** @var FaceManagementService*/
	protected static $faceMgmtService;
	
	/** @var FileService*/
	protected static $fileService;

	/** @var AddMissingImagesTask*/
	protected static $addMissingImagesTask;

	/** @var StaleImagesRemovalTask*/
	protected static $staleImagesRemovalTask;

	/** @var ImageMapper*/
	protected static $imageMapper;

	/** @var ClusterMapper*/
	protected static $clusterMapper;

	/** @var FaceMapper*/
	protected static $faceMapper;

	/** @var FaceRecognitionContext Context */
	protected static $context;

	/** @var View UserStorageSpace */
	protected static $view;

	/** @var IDBConnection test instance*/
	protected static $dbConnection;
	
	public static function setUpBeforeClass(): void {
		// Better safe than sorry. Warn user that database will be changed in chaotic manner:)
		if (false === getenv('TRAVIS')) {
			self::fail("This test touches database. Add \"TRAVIS\" env variable if you want to run these test on your local instance.");
		}
		parent::setUpBeforeClass();

		self::$dbConnection = OC::$server->getDatabaseConnection();
		self::clearDatabase();

		// Get container to get classes using DI
		$app = new App('facerecognition');
		self::$container = $app->getContainer();
		self::getInstaces();

		// Create user on which we will upload images and do testing
		$username = 'testuser' . rand(0, PHP_INT_MAX);
		self::$user = self::$userManager->createUser($username, 'YVvV4huLVUNR#UgJC*bBGXzHR4uW24$kB#dRTX*9');
		self::$user->updateLastLoginTimestamp();

		// The tests, by default, are with the analysis activated.
		self::$config->setUserValue(self::$user->getUID(), 'facerecognition', 'enabled', 'true');

		self::$originalModel = self::$settingsService->getCurrentFaceModel();
		self::$settingsService->setCurrentFaceModel(ModelManager::DEFAULT_FACE_MODEL_ID);

		
		self::$view = new View('/' . self::$user->getUID());
	}

	public function setUp(): void {
		// Better safe than sorry. Warn user that database will be changed in chaotic manner:)
		if (false === getenv('TRAVIS')) {
			self::fail("This test touches database. Add \"TRAVIS\" env variable if you want to run these test on your local instance.");
		}
		parent::setUp();
		self::clearDatabase();
		self::$context = new FaceRecognitionContext(self::$userManager, self::$config);
		$logger = self::$container->get('Psr\Log\LoggerInterface');
		self::$context->logger = new FaceRecognitionLogger($logger);
		self::$context->user = self::$user;
	}

	public function tearDown(): void {
		self::$view->rmdir('/files');
		parent::tearDown();
	}

	public static function tearDownAfterClass(): void {
		self::$settingsService->setCurrentFaceModel(self::$originalModel);
		self::$user->delete();
		self::cleanInstaces();
		parent::tearDownAfterClass();
	}

	private static function clearDatabase() : void{
		$sql = file_get_contents("tests/DatabaseInserts/00_emptyDatabase.sql");
		self::$dbConnection->executeStatement($sql);
	}

	private static function getInstaces() : void{
		self::$config = self::$container->get('OCP\IConfig');
		self::$appConfig = self::$container->get('OCP\IAppConfig');
		self::$userManager = self::$container->get('OCP\IUserManager');
		self::$settingsService = self::$container->get('OCA\FaceRecognition\Service\SettingsService');
		self::$fileService = self::$container->get('OCA\FaceRecognition\Service\FileService');
		self::$faceMgmtService = self::$container->get('OCA\FaceRecognition\Service\FaceManagementService');
		self::$imageMapper = self::$container->get('OCA\FaceRecognition\Db\ImageMapper');
		self::$clusterMapper =  self::$container->get('OCA\FaceRecognition\Db\ClusterMapper');
		self::$faceMapper = self::$container->get('OCA\FaceRecognition\Db\FaceMapper');
		self::$addMissingImagesTask = self::$container->get('OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask');
		self::$staleImagesRemovalTask = self::$container->get('OCA\FaceRecognition\BackgroundJob\Tasks\StaleImagesRemovalTask');
	}

	private static function cleanInstaces() : void{
		self::$config = null;
		self::$appConfig = null;
		self::$userManager = null;
		self::$settingsService = null;
		self::$fileService = null;
		self::$faceMgmtService = null;
		self::$imageMapper = null;
		self::$clusterMapper =  null;
		self::$faceMapper = null;
		self::$addMissingImagesTask = null;
		self::$staleImagesRemovalTask = null;
	}
}
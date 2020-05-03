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

/**
 * Main class that all integration tests should inherit from.
 */
abstract class IntegrationTestCase extends TestCase {
	/** @var IAppContainer */
	protected $container;

	/** @var FaceRecognitionContext Context */
	protected $context;

	/** @var IUser User */
	protected $user;

	/** @var IConfig Config */
	protected $config;

	public function setUp(): void {
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
		$userManager = $this->container->query('OCP\IUserManager');
		$this->config = $this->container->query('OCP\IConfig');
		$this->context = new FaceRecognitionContext($userManager, $this->config);
		$logger = $this->container->query('OCP\ILogger');
		$this->context->logger = new FaceRecognitionLogger($logger);

		// The tests, by default, are with the analysis activated.
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', 'enabled', 'true');
	}

	public function tearDown(): void {
		$faceMgmtService = $this->container->query('OCA\FaceRecognition\Service\FaceManagementService');
		$faceMgmtService->resetAllForUser($this->user->getUID());

		$this->user->delete();

		parent::tearDown();
	}
}
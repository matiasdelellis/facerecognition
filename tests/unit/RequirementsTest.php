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
namespace OCA\FaceRecognition\Tests\Unit;

use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;

use OCP\App\IAppManager;
use OCP\Files\IRootFolder;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

use Test\TestCase;

class RequirementsTest extends TestCase {
	/** @var FaceRecognitionContext Context */
	private $context;

	/**
	 * {@inheritDoc}
	 */
	public function setUp() {
		$appManager = $this->createMock(IAppManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$config = $this->createMock(IConfig::class);
		$logger = $this->createMock(ILogger::class);
		$this->context = new FaceRecognitionContext($appManager, $userManager, $rootFolder, $config);
		$this->context->logger = new FaceRecognitionLogger($logger);
	}

	public function testPdlibLoaded() {
		$appManager = $this->createMock(IAppManager::class);
		$requirements = new Requirements($appManager, AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID);
		$this->assertTrue($requirements->pdlibLoaded());
	}
}
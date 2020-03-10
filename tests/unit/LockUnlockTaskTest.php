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
use OCA\FaceRecognition\BackgroundJob\Tasks\LockTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\UnlockTask;
use OCA\FaceRecognition\Service\ModelService;

use Test\TestCase;

class LockTaskTest extends TestCase {
	/** @var FaceRecognitionContext Context */
	private $context;

	/**
	 * {@inheritDoc}
	 */
	public function setUp() {
		$userManager = $this->createMock(IUserManager::class);
		$config = $this->createMock(IConfig::class);
		$this->context = new FaceRecognitionContext($userManager, $config);
		$logger = $this->createMock(ILogger::class);
		$this->context->logger = new FaceRecognitionLogger($logger);
	}

	public function testLockUnlock() {
		$lockTask = new LockTask();
		$unlockTask = new UnlockTask();
		$this->assertTrue($lockTask->execute($this->context));
		$this->assertTrue($unlockTask->execute($this->context));
		$this->assertNotEquals("", $lockTask->description());
		$this->assertNotEquals("", $unlockTask->description());
	}

	public function testDoubleLock() {
		$lockTask = new LockTask();
		$unlockTask = new UnlockTask();
		$this->assertTrue($lockTask->execute($this->context));
		$this->assertFalse($lockTask->execute($this->context));
		$this->assertTrue($unlockTask->execute($this->context));
	}

	public function testEmptyUnlock() {
		$unlockTask = new UnlockTask();
		$this->assertFalse($unlockTask->execute($this->context));
	}

	public function testDoubleUnlock() {
		$lockTask = new LockTask();
		$unlockTask = new UnlockTask();
		$this->assertTrue($lockTask->execute($this->context));
		$this->assertTrue($unlockTask->execute($this->context));
		// Double unlock is OK, nobody cares if second time we failed
		$this->assertTrue($unlockTask->execute($this->context));
	}
}
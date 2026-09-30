<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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

use Test\TestCase;

use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Files\File;

use Psr\Log\LoggerInterface;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;

use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * What the tests of the tasks that search the faces and regions the user
 * marked share: a model whose finds the test decides, and a real photo to cut
 * the regions out of, so that the whole chain from the region to the pixels of
 * the photo runs as it does for real.
 *
 * The photo is lenna.jpg, 158 x 158 pixels. Each test sets the maximum area of
 * the model to the area of its crop, so the crop is analyzed at its own size
 * and what the model finds is in pixels of the crop, off by its offset only.
 */
abstract class ManualFaceTaskTestCase extends TestCase {

	protected const USER = 'alice';

	/** @var FaceMapper|\PHPUnit\Framework\MockObject\MockObject */
	protected $faceMapper;
	/** @var FileService|\PHPUnit\Framework\MockObject\MockObject */
	protected $fileService;
	/** @var SettingsService|\PHPUnit\Framework\MockObject\MockObject */
	protected $settingsService;
	/** @var ModelManager|\PHPUnit\Framework\MockObject\MockObject */
	protected $modelManager;
	/** @var IModel|\PHPUnit\Framework\MockObject\MockObject */
	protected $model;
	/** @var ITempManager */
	protected $tempManager;
	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	protected $logger;
	/** @var FaceRecognitionContext */
	protected $context;
	/** @var string[] Every line the task logged, at any level */
	protected $logged = [];

	/** @var string[] The lines the task logged as warnings */
	protected $warned = [];

	public function setUp(): void {
		parent::setUp();

		$this->faceMapper      = $this->createMock(FaceMapper::class);
		$this->fileService     = $this->createMock(FileService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->modelManager    = $this->createMock(ModelManager::class);
		$this->model           = $this->createMock(IModel::class);
		$this->tempManager     = \OC::$server->get(ITempManager::class);

		$this->modelManager->method('getCurrentModel')->willReturn($this->model);
		$this->model->method('getPreferredMimeType')->willReturn('image/jpeg');
		$this->settingsService->method('getCurrentFaceModel')->willReturn(1);
		$this->settingsService->method('getMinimumFaceSize')->willReturn(40);
		$this->settingsService->method('getMinimumConfidence')->willReturn(0.99);

		// Every file is lenna.jpg, unless a test says otherwise.
		$this->fileService->method('getLocalFile')->willReturn(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg');

		$this->logged = [];
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->warned = [];
		$this->logger->method('info')->willReturnCallback(function ($message) {
			$this->logged[] = (string) $message;
		});
		$this->logger->method('warning')->willReturnCallback(function ($message) {
			$this->logged[] = (string) $message;
			$this->warned[] = (string) $message;
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::USER);

		$this->context = new FaceRecognitionContext($this->createMock(IUserManager::class), $this->createMock(IConfig::class));
		$this->context->logger = new FaceRecognitionLogger($this->logger);
		$this->context->user = $user;
	}

	/** Every file id is a file of the user. */
	protected function everyFileExists(): void {
		$this->fileService->method('getFileById')->willReturn($this->createMock(File::class));
	}

	/**
	 * The model finds these faces, given as left/top/right/bottom in pixels of
	 * the crop, one list per call.
	 *
	 * @param array<int, array<int, array>> $findsPerCall
	 */
	protected function modelFinds(array ...$findsPerCall): void {
		$this->model->method('detectFaces')->willReturnOnConsecutiveCalls(...$findsPerCall);
	}

	protected static function rawFace(int $left, int $top, int $right, int $bottom, float $confidence, array $descriptor = [0.1, 0.2, 0.3]): array {
		return [
			'left' => $left, 'top' => $top, 'right' => $right, 'bottom' => $bottom,
			'detection_confidence' => $confidence,
			'landmarks' => [['x' => $left + 5, 'y' => $top + 5]],
			'descriptor' => $descriptor,
		];
	}

	/**
	 * Runs the task to its end, as the background job does.
	 *
	 * @return mixed what the task returned
	 */
	protected function runTask($task) {
		$generator = $task->execute($this->context);
		foreach ($generator as $_) {
		}
		return $generator->getReturn();
	}

	protected function assertLogged(string $fragment): void {
		foreach ($this->logged as $line) {
			if (strpos($line, $fragment) !== false) {
				$this->addToAssertionCount(1);
				return;
			}
		}
		$this->fail('Nothing logged with "' . $fragment . '", only: ' . implode(' | ', $this->logged));
	}

	/**
	 * A failure has to be a warning: the info lines are not in the Nextcloud
	 * log at its default level, when the job runs from cron.
	 */
	protected function assertWarned(string $fragment): void {
		foreach ($this->warned as $line) {
			if (strpos($line, $fragment) !== false) {
				$this->addToAssertionCount(1);
				return;
			}
		}
		$this->fail('No warning with "' . $fragment . '", the warnings were: ' . implode(' | ', $this->warned));
	}
}

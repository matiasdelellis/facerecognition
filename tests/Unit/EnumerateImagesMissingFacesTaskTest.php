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

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

use OCP\Files\File;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;

use Test\TestCase;

class EnumerateImagesMissingFacesTaskTest extends TestCase {

	/** @var SettingsService|\PHPUnit\Framework\MockObject\MockObject */
	private $settingsService;

	/** @var ImageMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $imageMapper;

	/** @var FileService|\PHPUnit\Framework\MockObject\MockObject */
	private $fileService;

	public function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getCurrentFaceModel')->willReturn(1);
		$this->settingsService->method('getRefinementEnabled')->willReturn(false);

		$this->imageMapper = $this->createMock(ImageMapper::class);
		$this->fileService = $this->createMock(FileService::class);
	}

	/**
	 * @return FaceRecognitionContext
	 */
	private function makeContext(array $propertyBag = [], ?string $userId = null): FaceRecognitionContext {
		$userManager = $this->createMock(IUserManager::class);
		$config = $this->createMock(IConfig::class);
		$context = new FaceRecognitionContext($userManager, $config);
		$context->propertyBag = $propertyBag;
		$context->logger = $this->createMock(FaceRecognitionLogger::class);

		if (!is_null($userId)) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$context->user = $user;
		}

		return $context;
	}

	private function makeImage(int $id, ?int $fileId = null): Image {
		$image = new Image();
		$image->setId($id);
		$image->setFile($fileId ?? $id);
		return $image;
	}

	/**
	 * A file of the user, that only has to answer which id it has, since that
	 * is what relates it to the row of an image.
	 */
	private function makeFile(int $fileId): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		return $file;
	}

	private function runTask(FaceRecognitionContext $context): array {
		$task = new EnumerateImagesMissingFacesTask($this->settingsService, $this->imageMapper, $this->fileService);
		$generator = $task->execute($context);
		foreach ($generator as $_) {
		}
		$this->assertTrue($generator->getReturn());
		return $context->propertyBag['images'];
	}

	/**
	 * @return int[]
	 */
	private function imageIds(array $images): array {
		return array_map(function (Image $image) {
			return $image->getId();
		}, $images);
	}

	public function testWithoutWorkerTakesAllImages() {
		$images = [];
		for ($i = 0; $i < 10; $i++) {
			$images[] = $this->makeImage($i);
		}
		$this->imageMapper->method('findImagesWithoutFaces')->willReturn($images);

		$assigned = $this->runTask($this->makeContext());
		$ids = $this->imageIds($assigned);
		sort($ids);
		$this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], $ids);
	}

	public function testWorkerTakesItsShare() {
		$images = [];
		for ($i = 0; $i < 10; $i++) {
			$images[] = $this->makeImage($i);
		}
		$this->imageMapper->method('findImagesWithoutFaces')->willReturn($images);

		// With 3 workers, worker 1 keeps the images whose id % 3 == 1
		$assigned = $this->runTask($this->makeContext([
			'run_mode' => 'default-mode',
			'worker_index' => 1,
			'worker_count' => 3,
		]));
		$ids = $this->imageIds($assigned);
		sort($ids);
		$this->assertEquals([1, 4, 7], $ids);
	}

	public function testWorkersPartitionAllImages() {
		$images = [];
		for ($i = 0; $i < 100; $i++) {
			$images[] = $this->makeImage($i);
		}
		$this->imageMapper->method('findImagesWithoutFaces')->willReturn($images);

		$all = [];
		for ($i = 0; $i < 4; $i++) {
			$assigned = $this->runTask($this->makeContext([
				'run_mode' => 'default-mode',
				'worker_index' => $i,
				'worker_count' => 4,
			]));
			$this->assertNotEmpty($assigned);
			$all = array_merge($all, $this->imageIds($assigned));
		}

		// Every image is assigned to exactly one worker: no duplicates, none lost
		sort($all);
		$this->assertEquals(range(0, 99), $all);
	}

	public function testPathTakesOnlyTheImagesUnderIt() {
		$images = [];
		for ($i = 0; $i < 10; $i++) {
			$images[] = $this->makeImage($i);
		}
		$this->imageMapper->method('findImagesWithoutFaces')->willReturn($images);

		// Of the whole library of the user, only these three files are under
		// the path that was asked for.
		$this->fileService->method('getPicturesFromPath')->willReturn([
			$this->makeFile(2), $this->makeFile(5), $this->makeFile(7),
		]);

		$assigned = $this->runTask($this->makeContext(['path' => 'Photos/Vacations'], 'user0'));
		$ids = $this->imageIds($assigned);
		sort($ids);
		$this->assertEquals([2, 5, 7], $ids);
	}

	public function testMissingPathTakesNoImages() {
		$images = [];
		for ($i = 0; $i < 10; $i++) {
			$images[] = $this->makeImage($i);
		}
		$this->imageMapper->method('findImagesWithoutFaces')->willReturn($images);

		// The path does not exist for the user, which is not the same as a
		// path that exists and has no images: nothing is analyzed either way.
		$this->fileService->method('getPicturesFromPath')->willReturn(null);

		$assigned = $this->runTask($this->makeContext(['path' => 'Photos/Typo'], 'user0'));
		$this->assertEmpty($assigned);
	}

	public function testPathAndWorkersTakeTheirShareOfIt() {
		$images = [];
		for ($i = 0; $i < 10; $i++) {
			$images[] = $this->makeImage($i);
		}
		$this->imageMapper->method('findImagesWithoutFaces')->willReturn($images);

		$this->fileService->method('getPicturesFromPath')->willReturn([
			$this->makeFile(1), $this->makeFile(2), $this->makeFile(3), $this->makeFile(4),
		]);

		// The images under the path are still partitioned between the workers,
		// so worker 1 of 2 only takes the odd ones of them.
		$assigned = $this->runTask($this->makeContext([
			'path' => 'Photos/Vacations',
			'run_mode' => 'default-mode',
			'worker_index' => 1,
			'worker_count' => 2,
		], 'user0'));
		$ids = $this->imageIds($assigned);
		sort($ids);
		$this->assertEquals([1, 3], $ids);
	}
}

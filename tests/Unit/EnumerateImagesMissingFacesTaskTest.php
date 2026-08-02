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
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Service\SettingsService;

use OCP\IConfig;
use OCP\IUserManager;

use Test\TestCase;

class EnumerateImagesMissingFacesTaskTest extends TestCase {

	/** @var SettingsService|\PHPUnit\Framework\MockObject\MockObject */
	private $settingsService;

	/** @var ImageMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $imageMapper;

	public function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getCurrentFaceModel')->willReturn(1);
		$this->settingsService->method('getRefinementEnabled')->willReturn(false);

		$this->imageMapper = $this->createMock(ImageMapper::class);
	}

	/**
	 * @return FaceRecognitionContext
	 */
	private function makeContext(array $propertyBag = []): FaceRecognitionContext {
		$userManager = $this->createMock(IUserManager::class);
		$config = $this->createMock(IConfig::class);
		$context = new FaceRecognitionContext($userManager, $config);
		$context->propertyBag = $propertyBag;
		return $context;
	}

	private function makeImage(int $id): Image {
		$image = new Image();
		$image->setId($id);
		return $image;
	}

	private function runTask(FaceRecognitionContext $context): array {
		$task = new EnumerateImagesMissingFacesTask($this->settingsService, $this->imageMapper);
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
}

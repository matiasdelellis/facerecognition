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

use OCP\Files\File;

use OCA\FaceRecognition\BackgroundJob\Tasks\ManualRegionTask;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\ManualRegionMapper;

/**
 * The search of a region for faces, with a model whose finds the test decides.
 *
 * The regions are drawn at 40,40 with 60 x 60 pixels. With the margin of 64
 * pixels the crop is the whole photo, 158 x 158, so what the model finds is
 * already in pixels of the photo.
 */
class ManualRegionTaskTest extends ManualFaceTaskTestCase {

	/** @var ManualRegionMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $regionMapper;

	/** @var Face[] The faces the task created */
	private $inserted = [];

	public function setUp(): void {
		parent::setUp();

		$this->regionMapper = $this->createMock(ManualRegionMapper::class);
		$this->model->method('getMaximumArea')->willReturn(158 * 158);

		$this->inserted = [];
		$this->faceMapper->method('insertRescanFace')->willReturnCallback(function (Face $face) {
			$face->setId(1000 + count($this->inserted));
			$this->inserted[] = $face;
			return $face;
		});
	}

	private function task(): ManualRegionTask {
		return new ManualRegionTask($this->faceMapper, $this->regionMapper, $this->fileService, $this->settingsService, $this->modelManager, $this->tempManager);
	}

	private static function pending(int $id, int $fileId = 500, int $imageId = 10): array {
		return ['id' => $id, 'image' => $imageId, 'file' => $fileId, 'x' => 40, 'y' => 40, 'width' => 60, 'height' => 60];
	}

	private function migratedWith(array $pending): void {
		$this->regionMapper->method('isAvailable')->willReturn(true);
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->regionMapper->method('findPending')->with(self::USER, 1)->willReturn($pending);
	}

	private function existingFace(int $x, int $y, int $width, int $height): Face {
		$face = new Face();
		$face->setId(1);
		$face->setX($x);
		$face->setY($y);
		$face->setWidth($width);
		$face->setHeight($height);
		return $face;
	}

	/**
	 * Every face of the region is created, with what the detector found: box,
	 * landmarks, descriptor and its confidence. Too small and too uncertain
	 * ones as well, and they are counted apart.
	 */
	public function testEveryFaceOfTheRegionIsCreatedAndCounted() {
		$this->migratedWith([self::pending(3)]);
		$this->everyFileExists();
		$this->faceMapper->method('findByImage')->with(10)->willReturn([]);
		$this->modelFinds([
			// Takes part.
			self::rawFace(10, 10, 60, 60, 1.05, [0.1]),
			// Smaller than the minimum of 40.
			self::rawFace(70, 10, 100, 40, 1.05, [0.2]),
			// Below the minimum confidence of 0.99.
			self::rawFace(10, 80, 60, 130, 0.6, [0.3]),
		]);

		$this->regionMapper->expects($this->once())->method('markDone')->with(3, 3, 1, 1);
		$this->regionMapper->expects($this->never())->method('markFailed');

		$this->assertTrue($this->runTask($this->task()));

		$this->assertCount(3, $this->inserted);
		$face = $this->inserted[2];
		$this->assertEquals(10, $face->image);
		$this->assertEquals([10, 80, 50, 50], [$face->x, $face->y, $face->width, $face->height]);
		$this->assertSame(0.6, $face->confidence);
		$this->assertEquals([0.3], $face->descriptor);
		$this->assertEquals([['x' => 15, 'y' => 85]], $face->landmarks);
	}

	/**
	 * A find in the place of a face the photo already has is that face, found
	 * again, and it is not created twice; the face that is there stays.
	 */
	public function testAFaceThePhotoHasIsNotCreatedAgain() {
		$this->migratedWith([self::pending(3)]);
		$this->everyFileExists();
		$this->faceMapper->method('findByImage')->willReturn([$this->existingFace(12, 12, 46, 46)]);
		$this->modelFinds([
			self::rawFace(10, 10, 60, 60, 1.05),
			self::rawFace(10, 80, 60, 130, 1.05),
		]);

		$this->regionMapper->expects($this->once())->method('markDone')->with(3, 1, 0, 0);

		$this->runTask($this->task());

		$this->assertCount(1, $this->inserted);
		$this->assertEquals(80, $this->inserted[0]->y);
	}

	/**
	 * The same region searched twice creates its faces once: the second time
	 * they are the faces the photo already has.
	 */
	public function testTheSameRegionTwiceCreatesNoDuplicates() {
		$this->migratedWith([self::pending(3), self::pending(4)]);
		$this->everyFileExists();
		$this->faceMapper->method('findByImage')->willReturnCallback(function () {
			return array_map(function (Face $face) {
				return $this->existingFace($face->x, $face->y, $face->width, $face->height);
			}, $this->inserted);
		});
		$finds = [self::rawFace(10, 10, 60, 60, 1.05), self::rawFace(80, 80, 130, 130, 1.05)];
		$this->modelFinds($finds, $finds);

		$this->runTask($this->task());

		$this->assertCount(2, $this->inserted);
	}

	/**
	 * Without a face in the region, it is done with nothing found.
	 */
	public function testARegionWithoutFacesIsDone() {
		$this->migratedWith([self::pending(3)]);
		$this->everyFileExists();
		$this->faceMapper->method('findByImage')->willReturn([]);
		$this->modelFinds([]);

		$this->regionMapper->expects($this->once())->method('markDone')->with(3, 0, 0, 0);

		$this->runTask($this->task());

		$this->assertCount(0, $this->inserted);
	}

	/**
	 * A region whose file cannot be read fails, with the reason, and the ones
	 * before and after it are searched all the same.
	 */
	public function testAnUnreadableFileBetweenTwoRegionsFailsAlone() {
		$this->migratedWith([self::pending(1, 500), self::pending(2, 404), self::pending(3, 500)]);
		$this->fileService->method('getFileById')->willReturnCallback(function (int $fileId) {
			return $fileId === 404 ? null : $this->createMock(File::class);
		});
		$this->faceMapper->method('findByImage')->willReturn([]);
		$this->modelFinds([self::rawFace(10, 10, 60, 60, 1.05)], [self::rawFace(80, 80, 130, 130, 1.05)]);

		$done = [];
		$this->regionMapper->method('markDone')->willReturnCallback(function (int $regionId) use (&$done) {
			$done[] = $regionId;
		});
		$this->regionMapper->expects($this->once())->method('markFailed')
			->with(2, $this->stringContains('404'));

		$this->assertTrue($this->runTask($this->task()));

		$this->assertEquals([1, 3], $done);
		$this->assertWarned('[manual faces] Region 2 on file 404');
	}

	/**
	 * An Error while creating the faces of a region stays with that region.
	 */
	public function testAnErrorOfOneRegionStaysWithIt() {
		$this->migratedWith([self::pending(1), self::pending(2)]);
		$this->everyFileExists();
		$calls = 0;
		$this->faceMapper->method('findByImage')->willReturnCallback(function () use (&$calls) {
			if ($calls++ === 0) {
				throw new \Error('broken');
			}
			return [];
		});
		$this->modelFinds([self::rawFace(10, 10, 60, 60, 1.05)], [self::rawFace(10, 10, 60, 60, 1.05)]);

		$this->regionMapper->expects($this->once())->method('markFailed')->with(1, 'broken');
		$this->regionMapper->expects($this->once())->method('markDone')->with(2, 1, 0, 0);

		$this->assertTrue($this->runTask($this->task()));
	}

	/**
	 * An Error outside of a single region ends this task and nothing else.
	 */
	public function testAnErrorOfTheTaskItselfDoesNotLeaveIt() {
		$this->regionMapper->method('isAvailable')->willReturn(true);
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->regionMapper->method('findPending')->willThrowException(new \Error('no such table'));

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('[manual faces] The regions marked by hand could not be searched');
	}

	/**
	 * The regions of photos that are gone are deleted on every run, before
	 * the search, and the run says how many.
	 */
	public function testTheRegionsOfPhotosThatAreGoneAreDeleted() {
		$this->regionMapper->method('isAvailable')->willReturn(true);
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->regionMapper->expects($this->once())->method('deleteOrphaned')->willReturn(2);
		$this->regionMapper->method('findPending')->willReturn([]);

		$this->assertTrue($this->runTask($this->task()));
		$this->assertLogged('[manual faces] Deleted 2 region(s) of photos that are gone');
	}

	/**
	 * Before the migration ran there is no table of regions, and the task
	 * steps aside, saying why.
	 */
	public function testWithoutTheTableTheTaskIsSkipped() {
		$this->regionMapper->method('isAvailable')->willReturn(false);
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->regionMapper->expects($this->never())->method('findPending');
		$this->model->expects($this->never())->method('open');

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('migration');
	}

	/**
	 * The faces of a region carry a state, so without its column the regions
	 * wait as well.
	 */
	public function testWithoutTheStateColumnTheTaskIsSkipped() {
		$this->regionMapper->method('isAvailable')->willReturn(true);
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->regionMapper->expects($this->never())->method('findPending');

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('migration');
	}
}

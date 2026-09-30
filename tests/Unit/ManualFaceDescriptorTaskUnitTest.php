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

use OCA\FaceRecognition\BackgroundJob\Tasks\ManualFaceDescriptorTask;

use OCA\FaceRecognition\Db\Face;

use OCA\FaceRecognition\Service\ManualFaceService;

/**
 * The search of a marking for a descriptor, with a model whose finds the test
 * decides, so that what is stored can be compared with what was found.
 *
 * The marking is drawn at 40,40 with 50 x 50 pixels, so with the margin of
 * 40% the crop is 20,20 to 110,110, and a find at 20,20 to 70,70 of the crop is
 * exactly the drawn box.
 */
class ManualFaceDescriptorTaskUnitTest extends ManualFaceTaskTestCase {

	/** @var ManualFaceService|\PHPUnit\Framework\MockObject\MockObject */
	private $manualFaces;

	public function setUp(): void {
		parent::setUp();
		// The crop is 90 x 90, analyzed at its own size.
		$this->model->method('getMaximumArea')->willReturn(90 * 90);
		$this->manualFaces = $this->createMock(ManualFaceService::class);
	}

	private function task(): ManualFaceDescriptorTask {
		return new ManualFaceDescriptorTask($this->faceMapper, $this->fileService, $this->settingsService, $this->modelManager, $this->tempManager, $this->manualFaces);
	}

	private static function pending(int $id, int $fileId = 500, ?int $cluster = null): array {
		return ['id' => $id, 'image' => 10, 'cluster' => $cluster, 'file' => $fileId, 'x' => 40, 'y' => 40, 'width' => 50, 'height' => 50];
	}

	/** A face of the photo, as findComparableFacesOfImage() gives it */
	private static function faceOnThePhoto(int $id, int $x, int $y, int $width, int $height): Face {
		$face = new Face();
		$face->setId($id);
		$face->setImage(10);
		$face->setX($x);
		$face->setY($y);
		$face->setWidth($width);
		$face->setHeight($height);
		return $face;
	}

	private function migratedWith(array $pending): void {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findManualFacesPendingDescriptor')->with(self::USER, 1)->willReturn($pending);
	}

	/**
	 * The marking takes the box, the descriptor and the confidence the
	 * detector gave the face, and not an invented one.
	 */
	public function testTheFoundFaceIsStoredWithTheConfidenceOfTheDetector() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 0.42, [0.5, 0.6])]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, [0.5, 0.6], 40, 40, 50, 50, 0.42, false);
		$this->faceMapper->expects($this->never())->method('markManualFaceNotGroupable');

		$this->assertTrue($this->runTask($this->task()));
	}

	/**
	 * When the detector puts the box somewhere else than where it was drawn,
	 * that is recorded, so the user can be told the box moved.
	 */
	public function testABoxThatMovedIsRecorded() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		// A face in the upper left corner, that only touches the drawn box
		// with a corner of 20 x 20 pixels.
		$this->modelFinds([self::rawFace(0, 0, 40, 40, 1.02)]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 20, 20, 40, 40, 1.02, true);

		$this->runTask($this->task());
	}

	/**
	 * A face that is only in the margin, and does not touch the drawn box, is
	 * someone else's: its descriptor is not taken for the marking.
	 */
	public function testAFaceOnlyInTheMarginIsNotTaken() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		// A face in the upper left corner of the margin, nowhere near the box.
		$this->modelFinds([self::rawFace(0, 0, 18, 18, 1.02)]);

		$this->faceMapper->expects($this->never())->method('setManualFaceDescriptor');
		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);

		$this->assertTrue($this->runTask($this->task()));
	}

	/**
	 * A box that stays where it was drawn is not reported as moved.
	 */
	public function testABoxThatStayedIsNotRecordedAsMoved() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		// A little tighter than drawn, but the same face.
		$this->modelFinds([self::rawFace(24, 24, 66, 66, 1.02)]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 44, 44, 42, 42, 1.02, false);

		$this->runTask($this->task());
	}

	/**
	 * Of several faces in the crop, the one on the drawn box is taken, even
	 * when a bigger one reaches into it from the margin.
	 */
	public function testTheFaceOnTheDrawnBoxIsTakenAndNotTheBiggest() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([
			// 45 x 45 at 65,65 of the photo: bigger, but mostly in the margin.
			self::rawFace(45, 45, 90, 90, 1.1),
			// 36 x 36 at 44,44 of the photo: inside the drawn box.
			self::rawFace(24, 24, 60, 60, 1.0),
		]);

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 44, 44, 36, 36, 1.0, false);

		$this->runTask($this->task());
	}

	/**
	 * The photo is looked for in the files of the user of the marking. The
	 * FileService keeps the user a task before set up, and would otherwise look
	 * in the files of that one.
	 */
	public function testThePhotoIsLookedForInTheFilesOfTheUserOfTheMarking() {
		$this->migratedWith([self::pending(7)]);
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);

		$calls = [];
		$this->fileService->method('setupFS')->willReturnCallback(function (string $userId) use (&$calls) {
			$calls[] = 'setupFS ' . $userId;
		});
		$this->fileService->method('getFileById')->willReturnCallback(function (int $fileId, $userId) use (&$calls) {
			$calls[] = 'getFileById ' . $fileId;
			return $this->createMock(File::class);
		});

		$this->runTask($this->task());

		$this->assertEquals(['setupFS ' . self::USER, 'getFileById 500'], $calls);
	}

	/**
	 * Without a face in the crop the marking records that there is none, and
	 * no descriptor is made up.
	 */
	public function testNoFaceIsRecorded() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([]);

		$this->faceMapper->expects($this->never())->method('setManualFaceDescriptor');
		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);

		$this->assertTrue($this->runTask($this->task()));
	}

	/**
	 * A marking whose file cannot be read is recorded as having no face, so
	 * that it is not taken again on every run, and the next one is searched.
	 */
	public function testAnUnreadableFileIsRecordedAndTheNextMarkingIsSearched() {
		$this->migratedWith([self::pending(7, 404), self::pending(8, 500)]);
		$this->fileService->method('getFileById')->willReturnCallback(function (int $fileId) {
			return $fileId === 404 ? null : $this->createMock(File::class);
		});
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);

		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);
		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(8, $this->anything(), 40, 40, 50, 50, 1.0, false);

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('[manual faces] Manual face 7 on file 404');
	}

	/**
	 * An Error, and not only an Exception, of a single marking stays with that
	 * marking: the background job would stop the whole run on anything that
	 * leaves a task.
	 */
	public function testAnErrorOfOneMarkingStaysWithIt() {
		$this->migratedWith([self::pending(7), self::pending(8)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)], [self::rawFace(20, 20, 70, 70, 1.0)]);

		$stored = [];
		$this->faceMapper->method('setManualFaceDescriptor')
			->willReturnCallback(function (int $faceId) use (&$stored) {
				if ($faceId === 7) {
					throw new \TypeError('broken');
				}
				$stored[] = $faceId;
			});
		$this->faceMapper->expects($this->once())->method('markManualFaceNotGroupable')->with(7);

		$this->assertTrue($this->runTask($this->task()));
		$this->assertEquals([8], $stored);
	}

	/**
	 * An Error outside of a single marking, here reading what is pending,
	 * ends this task and nothing else: it returns like it always does.
	 */
	public function testAnErrorOfTheTaskItselfDoesNotLeaveIt() {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findManualFacesPendingDescriptor')->willThrowException(new \Error('no such table'));

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('[manual faces] The faces marked by hand could not be searched');
	}

	/**
	 * Even recording that a marking failed can fail; the other markings are
	 * searched all the same.
	 */
	public function testAFailureToRecordAFailureDoesNotStopTheOthers() {
		$this->migratedWith([self::pending(7, 404), self::pending(8, 500)]);
		$this->fileService->method('getFileById')->willReturnCallback(function (int $fileId) {
			return $fileId === 404 ? null : $this->createMock(File::class);
		});
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);
		$this->faceMapper->method('markManualFaceNotGroupable')->willThrowException(new \RuntimeException('read only'));

		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')->with(8);

		$this->assertTrue($this->runTask($this->task()));
	}

	/**
	 * Before the migration ran there is no state to find the pending markings
	 * by, and the task steps aside, saying why.
	 */
	public function testBeforeTheMigrationTheTaskIsSkipped() {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->faceMapper->expects($this->never())->method('findManualFacesPendingDescriptor');
		$this->model->expects($this->never())->method('open');

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('migration');
	}

	/**
	 * The model is only opened when there is something to search.
	 */
	public function testTheModelIsNotOpenedWithoutWork() {
		$this->migratedWith([]);
		$this->model->expects($this->never())->method('open');

		$this->assertTrue($this->runTask($this->task()));
	}

	// --- A marking of a face already on the photo -------------------------

	/**
	 * The search finds a face that the analysis had found already, a little
	 * off: the marking is merged into that face, and not stored as a second
	 * box on top of it.
	 */
	public function testAFindThatIsAFaceAlreadyThereIsMergedIntoIt() {
		$this->migratedWith([self::pending(7, 500, 3)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);
		$there = self::faceOnThePhoto(99, 42, 43, 50, 48);
		$this->faceMapper->method('findComparableFacesOfImage')->with(10)->willReturn([$there]);

		$this->manualFaces->expects($this->once())->method('mergeMarking')->with(self::USER, 7, 3, $there)->willReturn(true);
		$this->faceMapper->expects($this->never())->method('setManualFaceDescriptor');
		$this->faceMapper->expects($this->never())->method('markManualFaceNotGroupable');

		$this->assertTrue($this->runTask($this->task()));
		$this->assertLogged('[manual faces] Manual face 7: the face it marks is face 99');
	}

	/**
	 * A face next to the one found is another face: the marking keeps what
	 * its search found.
	 */
	public function testAFindNextToAnotherFaceIsStoredAsBefore() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);
		$this->faceMapper->method('findComparableFacesOfImage')->willReturn([self::faceOnThePhoto(99, 95, 40, 50, 50)]);

		$this->manualFaces->expects($this->never())->method('mergeMarking');
		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor')
			->with(7, $this->anything(), 40, 40, 50, 50, 1.0, false);

		$this->runTask($this->task());
	}

	/**
	 * A marking the user ignored stays as it is, even on a face already
	 * there: merging it would bring its face back.
	 */
	public function testAnIgnoredMarkingIsNotMerged() {
		$this->migratedWith([self::pending(7, 500, 3)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);
		$this->faceMapper->method('findComparableFacesOfImage')->willReturn([self::faceOnThePhoto(99, 40, 40, 50, 50)]);
		$this->manualFaces->method('isIgnored')->with(self::USER, 3)->willReturn(true);

		$this->manualFaces->expects($this->never())->method('mergeMarking');
		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor');

		$this->runTask($this->task());
	}

	/**
	 * The markings found before this search merged them are merged once for
	 * each user, and that is recorded, so it does not run again.
	 */
	public function testTheMarkingsFoundBeforeAreMergedOnce() {
		$this->migratedWith([]);
		$this->settingsService->method('getManualDuplicatesMerged')->with(self::USER)->willReturn(false);
		$this->manualFaces->expects($this->once())->method('mergeFoundMarkings')->with(self::USER, 1)->willReturn(2);
		$this->settingsService->expects($this->once())->method('setManualDuplicatesMerged')->with(self::USER);

		$this->assertTrue($this->runTask($this->task()));
		$this->assertLogged('[manual faces] Merged 2 marking(s) of user alice');
	}

	public function testTheMarkingsFoundBeforeAreNotMergedTwice() {
		$this->migratedWith([]);
		$this->settingsService->method('getManualDuplicatesMerged')->willReturn(true);
		$this->manualFaces->expects($this->never())->method('mergeFoundMarkings');

		$this->assertTrue($this->runTask($this->task()));
	}

	/**
	 * A failure of that merge is a warning, is tried again on the next run,
	 * and the markings waiting for their search are still searched.
	 */
	public function testAFailingMergeOfTheMarkingsFoundBeforeIsTriedAgain() {
		$this->migratedWith([self::pending(7)]);
		$this->everyFileExists();
		$this->modelFinds([self::rawFace(20, 20, 70, 70, 1.0)]);
		$this->settingsService->method('getManualDuplicatesMerged')->willReturn(false);
		$this->manualFaces->method('mergeFoundMarkings')->willThrowException(new \RuntimeException('deadlock'));

		$this->settingsService->expects($this->never())->method('setManualDuplicatesMerged');
		$this->faceMapper->expects($this->once())->method('setManualFaceDescriptor');

		$this->assertTrue($this->runTask($this->task()));
		$this->assertWarned('could not be merged, they are left for the next run: deadlock');
	}
}

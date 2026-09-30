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
use OCP\Lock\ILockingProvider;

use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

/**
 * What the analysis of a photo does with the faces the user put there: the
 * ones marked by hand, the ones found in a region, and the ones moved to
 * another person. A face found by the analysis in the same place overwrites
 * the one that is there in the refinement, and is dropped in the fast pass.
 *
 * The analysis sees lenna.jpg at its own size, 158 x 158, and the model finds
 * a face at 50,50 with 50 x 50 pixels.
 */
class ImageProcessingManualFacesTest extends ManualFaceTaskTestCase {

	/** @var ImageMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $imageMapper;

	/** @var Image */
	private $image;

	/** @var array|null What imageProcessed() got: faces, error and pass */
	private $processed = null;

	/** @var array|null What overwriteWithAnalysis() got */
	private $overwritten = null;

	public function setUp(): void {
		parent::setUp();

		$this->image = new Image();
		$this->image->setId(10);
		$this->image->setUser(self::USER);
		$this->image->setFile(500);
		$this->image->setModel(1);
		// Due in both passes.
		$this->image->setIsProcessed(false);
		$this->image->setIsRefined(false);

		$this->imageMapper = $this->createMock(ImageMapper::class);
		$this->imageMapper->method('find')->willReturn($this->image);
		$this->imageMapper->method('imageProcessed')->willReturnCallback(
			function (Image $image, array $faces, int $duration, ?\Exception $e = null, bool $refined = false) {
				$this->processed = ['faces' => $faces, 'error' => $e, 'refined' => $refined];
			});

		$this->everyFileExists();
		$this->fileService->method('isAllowedNode')->willReturn(true);

		$this->settingsService->method('getAnalysisImageArea')->willReturn(158 * 158);
		$this->settingsService->method('getFastPassImageArea')->willReturn(158 * 158);
		$this->settingsService->method('getMinimumImageSize')->willReturn(1);

		// The fast pass runs the HOG model, which is this same mock.
		$this->modelManager->method('getModel')->willReturn($this->model);
		$this->model->method('isInstalled')->willReturn(true);
		$this->model->method('meetDependencies')->willReturn(true);

		$this->model->method('detectFaces')->willReturn([self::rawFace(50, 50, 100, 100, 1.0)]);

		$this->processed = null;
		$this->overwritten = null;
		$this->faceMapper->method('overwriteWithAnalysis')->willReturnCallback(function (array $overwrites) {
			$this->overwritten = $overwrites;
		});

		$this->context->propertyBag['images'] = [$this->image];
	}

	private function analyze(bool $fastPass): void {
		$this->context->propertyBag['run_mode'] = $fastPass ? 'fast-mode' : 'default-mode';

		$task = new ImageProcessingTask($this->imageMapper, $this->faceMapper, $this->fileService,
			$this->settingsService, $this->modelManager, $this->createMock(ILockingProvider::class));
		$this->assertTrue($this->runTask($task));
		$this->assertNotNull($this->processed, 'The photo must have been processed');
		$this->assertNull($this->processed['error'], 'The photo must not record an error');
	}

	private function oldFace(int $id, int $x, int $y, int $size, ?int $cluster, bool $groupable, bool $manual, ?string $state): Face {
		$face = new Face();
		$face->setId($id);
		$face->setImage(10);
		$face->setCluster($cluster);
		$face->setX($x);
		$face->setY($y);
		$face->setWidth($size);
		$face->setHeight($size);
		$face->setIsGroupable($groupable);
		$face->setIsManual($manual);
		$face->setManualState($state);
		return $face;
	}

	/** The image has these faces, of which the manual ones are put there by the user. */
	private function imageHas(Face ...$faces): void {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findByImage')->with(10)->willReturn($faces);
		$this->faceMapper->method('findManualFacesOfImage')->with(10)->willReturn(
			array_values(array_filter($faces, function (Face $face) {
				return (bool) $face->getIsManual();
			})));
	}

	/**
	 * A marking whose search found no face is found by the analysis: it takes
	 * over what the analysis found, keeps its row, is confirmed, and gets back
	 * into the clustering. No second face is created.
	 */
	public function testAMarkingWithoutFaceIsConfirmedAndRegrouped() {
		$this->imageHas($this->oldFace(77, 52, 52, 46, 7, false, true, Face::MANUAL_STATE_NO_FACE));

		$this->analyze(false);

		$this->assertCount(0, $this->processed['faces']);
		$this->assertEquals([77], array_keys($this->overwritten));
		$this->assertTrue($this->overwritten[77]['confirm']);
		$this->assertTrue($this->overwritten[77]['regroup']);
		$face = $this->overwritten[77]['face'];
		$this->assertEquals([50, 50, 50, 50], [$face->getX(), $face->getY(), $face->getWidth(), $face->getHeight()]);
		$this->assertEquals([0.1, 0.2, 0.3], $face->descriptor);
		$this->assertEquals(1.0, $face->getConfidence());
	}

	/**
	 * A face the user moved to another person is overwritten as well, but it
	 * is not a marking, and it stays out of the clustering: the user took it
	 * out of its group.
	 */
	public function testAMovedFaceStaysOutOfTheClustering() {
		$this->imageHas($this->oldFace(77, 52, 52, 46, 7, false, true, null));

		$this->analyze(false);

		$this->assertCount(0, $this->processed['faces']);
		$this->assertFalse($this->overwritten[77]['confirm']);
		$this->assertFalse($this->overwritten[77]['regroup']);
	}

	/**
	 * A marking the search already found keeps its groupability, whatever it
	 * is: if it is out of the clustering, the user took it out.
	 */
	public function testAFoundMarkingIsConfirmedWithoutRegrouping() {
		$this->imageHas($this->oldFace(77, 52, 52, 46, null, true, true, Face::MANUAL_STATE_FOUND));

		$this->analyze(false);

		$this->assertCount(0, $this->processed['faces']);
		$this->assertTrue($this->overwritten[77]['confirm']);
		$this->assertFalse($this->overwritten[77]['regroup']);
	}

	/**
	 * The fast pass works on a small image: the face it finds is dropped, and
	 * the face the user put there is not touched.
	 */
	public function testTheFastPassDropsTheFindAndLeavesTheManualFace() {
		$this->imageHas($this->oldFace(77, 52, 52, 46, null, true, true, Face::MANUAL_STATE_FOUND));

		$this->analyze(true);

		$this->assertCount(0, $this->processed['faces']);
		$this->assertEquals([], $this->overwritten);
		$this->assertFalse($this->processed['refined']);
	}

	/**
	 * A face the user put somewhere else stays as it is, and the find of the
	 * analysis is created next to it.
	 */
	public function testAManualFaceElsewhereIsLeftAlone() {
		$this->imageHas($this->oldFace(77, 0, 0, 30, null, true, true, Face::MANUAL_STATE_FOUND));

		$this->analyze(false);

		$this->assertCount(1, $this->processed['faces']);
		$this->assertEquals([], $this->overwritten);
	}

	/**
	 * A face of the analysis found again keeps its cluster, as it always did.
	 */
	public function testAFaceOfTheAnalysisFoundAgainKeepsItsCluster() {
		$this->imageHas($this->oldFace(66, 52, 52, 46, 9, true, false, null));

		$this->analyze(false);

		$this->assertCount(1, $this->processed['faces']);
		$this->assertEquals(9, $this->processed['faces'][0]->getCluster());
		$this->assertEquals([], $this->overwritten);
	}

	/**
	 * If matching with the faces put there by hand fails, the photo is
	 * processed as before, and it does not record an error: a photo with an
	 * error is not analyzed again until the user resets the errors.
	 */
	public function testAFailureOfTheMatchingProcessesThePhotoAsBefore() {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findManualFacesOfImage')->willThrowException(new \RuntimeException('no such column'));
		$this->faceMapper->method('findByImage')->willReturn([]);
		$this->faceMapper->expects($this->never())->method('overwriteWithAnalysis');

		$this->analyze(false);

		$this->assertCount(1, $this->processed['faces']);
		$this->assertTrue($this->processed['refined']);
		$this->assertWarned('[manual faces] Image 10');
	}

	/**
	 * If writing into the faces put there by hand fails, the photo is
	 * processed as before as well.
	 */
	public function testAFailureOfTheOverwriteProcessesThePhotoAsBefore() {
		$manual = $this->oldFace(77, 52, 52, 46, 7, true, true, Face::MANUAL_STATE_FOUND);

		// A mapper of its own, since the one of setUp() records the overwrite.
		$this->faceMapper = $this->createMock(FaceMapper::class);
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->faceMapper->method('findManualFacesOfImage')->willReturn([$manual]);
		$this->faceMapper->method('findByImage')->willReturn([$manual]);
		$this->faceMapper->method('overwriteWithAnalysis')->willThrowException(new \RuntimeException('deadlock'));

		$this->analyze(false);

		// As before: the find is created, and inherits the cluster of the face
		// in its place.
		$this->assertCount(1, $this->processed['faces']);
		$this->assertEquals(7, $this->processed['faces'][0]->getCluster());
		$this->assertWarned('deadlock');
	}

	/**
	 * Before the migration ran, the faces put there by hand cannot be told
	 * apart, and the photo is processed as before, without trying.
	 */
	public function testBeforeTheMigrationThePhotoIsProcessedAsBefore() {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->faceMapper->method('findByImage')->willReturn([]);
		$this->faceMapper->expects($this->never())->method('findManualFacesOfImage');

		$this->analyze(false);

		$this->assertCount(1, $this->processed['faces']);
	}
}

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
namespace OCA\FaceRecognition\Tests\Integration;

use OCA\FaceRecognition\BackgroundJob\Tasks\ManualFaceDescriptorTask;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Helper\ManualFaceDetector;

use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FileService;

/**
 * Integration coverage for ManualFaceDescriptorTask: a manual face should get a
 * real descriptor, and the confidence the detector really gave it, when the
 * model finds a face in the marked region, and should be excluded from
 * clustering (no crash, no fake descriptor) when it does not. Either way the
 * outcome is recorded in its state, and it is not searched again.
 *
 * @group DB
 */
class ManualFaceDescriptorTaskTest extends ManualFaceIntegrationTestCase {

	/**
	 * A manual face drawn over a real face gets a descriptor, the box of the
	 * face and the confidence the detector gave it, and it is found.
	 */
	public function testDescriptorComputedForRealFace() {
		$image = $this->upload('lenna.jpg', $this->lenna());
		// A generous box that covers the face in lenna.jpg (158x158).
		$face = $this->insertMarking($image->getId(), 10, 10, 138, 138);

		$this->runDescriptorTask();

		$row = $this->row($face->getId());
		$this->assertNotEmpty($row['descriptor'], 'A descriptor must be computed for the marked face');
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $row['manual_state']);
		$this->assertTrue($row['is_groupable']);

		// The stored box must snap to the detected face, not stay as the generous
		// user rectangle, so box and descriptor describe the same face.
		$this->assertLessThan(138, $row['width'], 'Box width should shrink to the detected face');
		$this->assertLessThan(138, $row['height'], 'Box height should shrink to the detected face');
		$this->assertGreaterThan(0, $row['width']);
		$this->assertGreaterThan(0, $row['height']);
		// The detected box must stay within the original image bounds.
		$this->assertGreaterThanOrEqual(0, $row['x']);
		$this->assertGreaterThanOrEqual(0, $row['y']);
		$this->assertLessThanOrEqual(158, $row['x'] + $row['width']);
		$this->assertLessThanOrEqual(158, $row['y'] + $row['height']);

		// The confidence is the one of the detection, found again here with the
		// same crop, and not a value assumed for it.
		$detected = $this->detectAgain($image->getFile(), ['x' => 10, 'y' => 10, 'width' => 138, 'height' => 138]);
		$this->assertEqualsWithDelta($detected['confidence'], $row['confidence'], 0.0001);
		$this->assertEquals([$detected['x'], $detected['y'], $detected['width'], $detected['height']],
			[$row['x'], $row['y'], $row['width'], $row['height']]);

		// No longer pending: it now has a descriptor and will be clustered.
		$this->assertCount(0, $this->pendingMarkings());
	}

	/**
	 * A manual face drawn where there is no face is excluded from clustering
	 * (no descriptor, no exception), and records that there was no face.
	 */
	public function testNoFaceLeavesFaceWithoutDescriptor() {
		$image = $this->upload('black.jpg', $this->black());
		$face = $this->insertMarking($image->getId(), 10, 10, 100, 100);

		$this->runDescriptorTask();

		$row = $this->row($face->getId());
		$this->assertEmpty($row['descriptor'], 'No descriptor should be stored when no face is found');
		$this->assertEquals(Face::MANUAL_STATE_NO_FACE, $row['manual_state']);
		$this->assertFalse($row['is_groupable']);
		// The drawn box stays.
		$this->assertEquals([10, 10, 100, 100], [$row['x'], $row['y'], $row['width'], $row['height']]);

		$this->assertCount(0, $this->pendingMarkings());
	}

	/**
	 * A marking whose file is gone is recorded as having no face, and it is
	 * not searched again on the next run.
	 */
	public function testAMarkingThatFailsIsNotSearchedAgain() {
		$image = $this->imageOfAMissingFile();
		$face = $this->insertMarking($image->getId(), 10, 10, 100, 100);
		$this->assertCount(1, $this->pendingMarkings());

		$this->runDescriptorTask();

		$this->assertEquals(Face::MANUAL_STATE_NO_FACE, $this->row($face->getId())['manual_state']);
		$this->assertCount(0, $this->pendingMarkings());

		// The next run leaves it alone.
		$this->runDescriptorTask();
		$this->assertEquals(Face::MANUAL_STATE_NO_FACE, $this->row($face->getId())['manual_state']);
		$this->assertCount(0, $this->pendingMarkings());
	}

	private function pendingMarkings(): array {
		return $this->container->query(FaceMapper::class)
			->findManualFacesPendingDescriptor($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
	}

	/**
	 * What the detector finds in the region of a marking, the face on the
	 * drawn box, as the task searches it: same margin, same scale.
	 */
	private function detectAgain(int $fileId, array $rect): array {
		$model = $this->container->query(ModelManager::class)->getCurrentModel();
		$model->open();
		$detector = new ManualFaceDetector($this->container->query(FileService::class), $this->container->query('OCP\ITempManager'));
		$faces = $detector->detect($model, $this->user->getUID(), $fileId, $rect,
			(int) round($rect['width'] * 0.4), (int) round($rect['height'] * 0.4),
			min($rect['width'], $rect['height']));
		$detector->clean();

		$face = ManualFaceDescriptorTask::pickMarkedFace($rect, $faces);
		$this->assertNotNull($face);
		return $face;
	}
}

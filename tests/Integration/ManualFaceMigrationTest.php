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

use OCP\IDBConnection;
use OCP\Migration\IOutput;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Migration\Version0997Date20260923000000;

use OCA\FaceRecognition\Model\ModelManager;

/**
 * The markings that existed before the state was kept are searched again, and
 * the faces the user only moved to another person are left as they are.
 *
 * @group DB
 */
class ManualFaceMigrationTest extends ManualFaceIntegrationTestCase {

	private function migrate(): void {
		$migration = new Version0997Date20260923000000($this->container->query(IDBConnection::class));
		$migration->postSchemaChange($this->createMock(IOutput::class), function () {
			return null;
		}, []);
	}

	/**
	 * A marking as the old code left it: no state, the invented confidence,
	 * and non-groupable, because the search gave up on it or because the user
	 * never asked for it.
	 */
	private function oldMarking(int $imageId, int $x): Face {
		$marking = $this->insertMarking($imageId, $x, 10, 40, 40);
		$this->setColumns($marking->getId(), [
			'manual_state' => null,
			'is_groupable' => false,
			'confidence' => 1.0,
		]);
		return $marking;
	}

	public function testOldMarkingsAreSearchedAgain() {
		$image = $this->upload('lenna.jpg', $this->lenna());
		$first = $this->oldMarking($image->getId(), 10);
		$second = $this->oldMarking($image->getId(), 60);
		// One the old search had found, with a descriptor.
		$this->setColumns($second->getId(), ['descriptor' => self::descriptor(0.0), 'is_groupable' => true]);

		$this->migrate();

		foreach ([$first, $second] as $marking) {
			$row = $this->row($marking->getId());
			$this->assertEquals(Face::MANUAL_STATE_PENDING, $row['manual_state']);
			$this->assertTrue($row['is_groupable']);
		}

		// And the search does take them.
		$pending = array_map(function (array $row) {
			return (int) $row['id'];
		}, $this->container->query(FaceMapper::class)->findManualFacesPendingDescriptor($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID));
		$this->assertEquals([$first->getId(), $second->getId()], $pending);
	}

	/**
	 * A face the user moved to another person is manual too, but it has the
	 * landmarks of the analysis, and the user left it non-groupable: the
	 * migration touches neither its state nor its groupability. Nor does it
	 * touch a face of the analysis.
	 */
	public function testMovedFacesAndFacesOfTheAnalysisAreLeftAlone() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$image = $this->upload('lenna.jpg', $this->lenna());

		$moved = $faceMapper->insertFace(Face::fromModel($image->getId(), [
			'left' => 49, 'right' => 124, 'top' => 62, 'bottom' => 137,
			'detection_confidence' => 1.02,
			'landmarks' => [['x' => 60, 'y' => 80], ['x' => 100, 'y' => 80]],
			'descriptor' => self::descriptor(0.0),
		]));
		$faceMapper->markFaceManual($moved->getId());
		$this->setColumns($moved->getId(), ['is_groupable' => false]);

		$analyzed = $faceMapper->insertFace(Face::fromModel($image->getId(), [
			'left' => 0, 'right' => 40, 'top' => 0, 'bottom' => 40,
			'detection_confidence' => 1.0,
			'landmarks' => [['x' => 10, 'y' => 10]],
			'descriptor' => self::descriptor(0.1),
		]));

		$this->migrate();

		$row = $this->row($moved->getId());
		$this->assertNull($row['manual_state']);
		$this->assertFalse($row['is_groupable']);
		$this->assertTrue($row['is_manual']);
		$this->assertEqualsWithDelta(1.02, $row['confidence'], 0.0001);

		$row = $this->row($analyzed->getId());
		$this->assertNull($row['manual_state']);
		$this->assertTrue($row['is_groupable']);
	}

	/**
	 * A marking that already has a state is not put back to pending.
	 */
	public function testMarkingsWithAStateAreLeftAlone() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$image = $this->upload('lenna.jpg', $this->lenna());
		$marking = $this->insertMarking($image->getId(), 10, 10, 40, 40);
		$faceMapper->markManualFaceNotGroupable($marking->getId());

		$this->migrate();

		$row = $this->row($marking->getId());
		$this->assertEquals(Face::MANUAL_STATE_NO_FACE, $row['manual_state']);
		$this->assertFalse($row['is_groupable']);
	}
}

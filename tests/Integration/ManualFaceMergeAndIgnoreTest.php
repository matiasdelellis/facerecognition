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

use OCP\AppFramework\Db\DoesNotExistException;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * A marking that turns out to be a face already on the photo is merged into
 * it, and the user deletes and ignores faces; all of it on the database.
 *
 * The face of lenna.jpg is at 49,62, 75 pixels wide. The markings are drawn
 * loosely around it, as a user does.
 *
 * @group DB
 */
class ManualFaceMergeAndIgnoreTest extends ManualFaceIntegrationTestCase {

	/** @var Image */
	private $image;

	public function setUp(): void {
		parent::setUp();
		$this->image = $this->upload('lenna.jpg', $this->lenna());
	}

	private function service(): ManualFaceService {
		return $this->container->query(ManualFaceService::class);
	}

	/** The one face the analysis finds on the photo. */
	private function faceOfTheAnalysis(): int {
		$this->analyze(false);
		$faceIds = $this->faceIdsOf($this->image->getId());
		$this->assertCount(1, $faceIds, 'The analysis finds the face of lenna');
		return $faceIds[0];
	}

	private function face(int $faceId): Face {
		$face = $this->container->query(FaceMapper::class)->find($faceId);
		$this->assertNotNull($face);
		return $face;
	}

	private function clusterExists(int $clusterId): bool {
		try {
			$this->container->query(ClusterMapper::class)->find($this->user->getUID(), $clusterId);
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		}
	}

	private function isHidden(?int $clusterId): bool {
		$this->assertNotNull($clusterId);
		return !$this->container->query(ClusterMapper::class)->find($this->user->getUID(), $clusterId)->getIsVisible();
	}

	/**
	 * A marking drawn around a face the analysis found already: after its
	 * search there is one face, the one of the analysis, and it has the name
	 * the marking was saved with.
	 */
	public function testAMarkingOfAFaceOfTheAnalysisIsMergedIntoItWithItsName() {
		$autoId = $this->faceOfTheAnalysis();
		$alice = $this->clusterOf('Alice');
		$marking = $this->insertMarking($this->image->getId(), 30, 40, 110, 110, $alice);

		$this->runDescriptorTask();

		$this->assertEquals([$autoId], $this->faceIdsOf($this->image->getId()));
		$row = $this->row($autoId);
		$this->assertEquals($alice, $row['cluster'], 'The face is in the group of the marking');
		$this->assertEquals('Alice', $this->nameOfCluster($row['cluster']));
		$this->assertTrue($row['is_manual'], 'A later analysis keeps the name');
		$this->assertNull($this->row($marking->getId()));
	}

	/**
	 * Without a name the marking just goes, and the face of the analysis
	 * stays in the group the clustering put it in.
	 */
	public function testAMarkingWithoutNameOfAFaceOfTheAnalysisGoes() {
		$autoId = $this->faceOfTheAnalysis();
		$this->runClustering();
		$before = $this->row($autoId);
		$this->assertNotNull($before['cluster']);

		$this->insertMarking($this->image->getId(), 30, 40, 110, 110);
		$this->runDescriptorTask();

		$this->assertEquals([$autoId], $this->faceIdsOf($this->image->getId()));
		$after = $this->row($autoId);
		$this->assertEquals($before['cluster'], $after['cluster']);
		$this->assertFalse($after['is_manual']);
	}

	/**
	 * A marking next to a face of the analysis is another face, and stays.
	 */
	public function testAMarkingElsewhereStays() {
		$autoId = $this->faceOfTheAnalysis();
		$elsewhere = $this->insertRescanFace($this->image->getId(), ['x' => 0, 'y' => 0, 'width' => 30, 'height' => 30], self::descriptor(0.2));

		$this->assertEquals(0, $this->service()->mergeFoundMarkings($this->user->getUID(), self::MODEL_ID));

		$this->assertEqualsCanonicalizing([$autoId, $elsewhere->getId()], $this->faceIdsOf($this->image->getId()));
	}

	/**
	 * The markings searched before the search merged them are merged by the
	 * next run, once: a duplicate made afterwards is not looked for again.
	 */
	public function testTheMarkingsSearchedBeforeAreMergedOnce() {
		$autoId = $this->faceOfTheAnalysis();
		$auto = $this->row($autoId);
		$duplicate = function (string $name) use ($auto): int {
			$marking = $this->insertMarking($this->image->getId(), 30, 40, 110, 110, $this->clusterOf($name));
			$this->setColumns($marking->getId(), [
				'manual_state' => Face::MANUAL_STATE_FOUND,
				'descriptor' => self::descriptor(0.1),
				'x' => $auto['x'] + 2, 'y' => $auto['y'] + 3,
				'width' => $auto['width'], 'height' => $auto['height'],
			]);
			return $marking->getId();
		};

		$first = $duplicate('Alice');
		$this->runDescriptorTask();

		$this->assertNull($this->row($first));
		$this->assertEquals('Alice', $this->nameOfCluster($this->row($autoId)['cluster']));
		$this->assertTrue($this->container->query(SettingsService::class)->getManualDuplicatesMerged($this->user->getUID()));

		$second = $duplicate('Bob');
		$this->runDescriptorTask();

		$this->assertNotNull($this->row($second), 'The merge of the markings searched before runs once');
	}

	/**
	 * Two markings of the same face: the newer goes into the older, whose
	 * face takes the newer name, which is the last one the user gave.
	 */
	public function testTheNewerOfTwoMarkingsOfTheSameFaceGoesIntoTheOlder() {
		$older = $this->insertRescanFace($this->image->getId(), ['x' => 49, 'y' => 62, 'width' => 75, 'height' => 75], self::descriptor(0.1));
		$this->container->query(ClusterMapper::class)->attachFaces([$older->getId()], $this->clusterOf('Alice'));
		$newer = $this->insertRescanFace($this->image->getId(), ['x' => 52, 'y' => 60, 'width' => 74, 'height' => 78], self::descriptor(0.2));
		$this->container->query(ClusterMapper::class)->attachFaces([$newer->getId()], $this->clusterOf('Bob'));

		$this->assertEquals(1, $this->service()->mergeFoundMarkings($this->user->getUID(), self::MODEL_ID));

		$this->assertEquals([$older->getId()], $this->faceIdsOf($this->image->getId()));
		$this->assertEquals('Bob', $this->nameOfCluster($this->row($older->getId())['cluster']));
		// Alice has no face left, and neither a group nor a person.
		$this->assertNull($this->container->query(PersonMapper::class)->findByName($this->user->getUID(), 'Alice'));
	}

	/**
	 * An ignored face is in a hidden group of its own, out of the clustering,
	 * and leaves its group. The clustering leaves it there. Not ignoring it
	 * any more gives it back to the clustering, which places it.
	 */
	public function testAFaceIsIgnoredAndNotAnyMore() {
		$autoId = $this->faceOfTheAnalysis();
		$this->runClustering();
		$group = $this->row($autoId)['cluster'];

		$this->assertEquals([$autoId], $this->service()->ignore($this->user->getUID(), self::MODEL_ID, [$this->face($autoId)]));

		$row = $this->row($autoId);
		$this->assertNotEquals($group, $row['cluster']);
		$this->assertTrue($this->isHidden($row['cluster']));
		$this->assertFalse($row['is_groupable']);
		$this->assertTrue($row['is_manual'], 'A later analysis keeps it ignored');
		$this->assertFalse($this->clusterExists($group), 'The group it left had no other face');

		// Ignoring it again changes nothing.
		$this->assertEquals([], $this->service()->ignore($this->user->getUID(), self::MODEL_ID, [$this->face($autoId)]));

		$this->runClustering();
		$this->assertEquals($row['cluster'], $this->row($autoId)['cluster'], 'The clustering leaves an ignored face alone');

		$this->assertEquals([$autoId], $this->service()->unignore($this->user->getUID(), [$this->face($autoId)]));
		$back = $this->row($autoId);
		$this->assertNull($back['cluster']);
		$this->assertTrue($back['is_groupable']);
		$this->assertFalse($this->clusterExists($row['cluster']), 'The hidden group is gone with its face');

		$this->runClustering();
		$placed = $this->row($autoId)['cluster'];
		$this->assertNotNull($placed);
		$this->assertFalse($this->isHidden($placed));
	}

	/**
	 * A marking whose search found no face, and that the user ignored, stays
	 * out of the clustering when the analysis finds a face there: in its
	 * hidden group it would be a sample, and faces would join it there.
	 */
	public function testAnIgnoredMarkingWithoutFaceIsNotRegroupedByTheAnalysis() {
		$marking = $this->insertMarking($this->image->getId(), 49, 62, 75, 75);
		$this->container->query(FaceMapper::class)->markManualFaceNotGroupable($marking->getId());
		$this->service()->ignore($this->user->getUID(), self::MODEL_ID, [$this->face($marking->getId())]);

		$this->analyze(false);

		$row = $this->row($marking->getId());
		$this->assertEquals([$marking->getId()], $this->faceIdsOf($this->image->getId()), 'The analysis took the marking over');
		$this->assertFalse($row['is_groupable']);
		$this->assertTrue($this->isHidden($row['cluster']));
	}

	/**
	 * A deleted marking is gone, and so are its group and its person when
	 * nothing else is theirs.
	 */
	public function testADeletedMarkingTakesItsGroupAndPersonAlong() {
		$alice = $this->clusterOf('Alice');
		$marking = $this->insertMarking($this->image->getId(), 30, 40, 110, 110, $alice);

		$this->service()->delete($this->user->getUID(), [$this->face($marking->getId())]);

		$this->assertNull($this->row($marking->getId()));
		$this->assertFalse($this->clusterExists($alice));
		$this->assertNull($this->container->query(PersonMapper::class)->findByName($this->user->getUID(), 'Alice'));
	}
}

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

use Test\TestCase;

use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;

class MergeClustersTest extends TestCase {
	/** @var CreateClustersTask Create cluster task */
	private $createClusterTask;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		$personMapper = $this->getMockBuilder(PersonMapper::class)
			->disableOriginalConstructor()
			->getMock();
		$imageMapper = $this->getMockBuilder(ImageMapper::class)
			->disableOriginalConstructor()
			->getMock();
		$faceMapper = $this->getMockBuilder(FaceMapper::class)
			->disableOriginalConstructor()
			->getMock();
		$settingsService = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$this->createClusterTask = new CreateClustersTask($personMapper, $imageMapper, $faceMapper, $settingsService);
	}

	/**
	 * Tests cluster merging. Starts with simple cases and go to more complex ones. IDs that are used
	 * do not have any significance, they are mostly random, except that ID<100 are for person IDs,
	 * and IDs>100 are reserved for face IDs (this is just convention in test, to make reading easier).
	 */
	public function testMergeClustersSimple() {
		// Case when old cluster is empty and we get some new clusters
		//
		$result = $this->createClusterTask->mergeClusters(array(), array(1=>[101,102], 2=>[103,104]));
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[1], [101, 102]);
		$this->assertEquals($result[2], [103, 104]);
		// Case when old and new cluster are completely same
		//
		$c = array(3=>[101,103], 4=>[105,107]);
		$result = $this->createClusterTask->mergeClusters($c, $c);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[3], [101, 103]);
		$this->assertEquals($result[4], [105, 107]);
		// Case when cluster are the same, but person ID differ
		//
		$old = array(5=>[102,103], 6=>[105,106]);
		$new = array(1=>[102,103], 2=>[105,106]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[5], [102, 103]);
		$this->assertEquals($result[6], [105, 106]);
		// Case when new faces are added to existing cluster
		//
		$old = array(7=>[102,103], 8=>[105,106]);
		$new = array(1=>[102,103], 2=>[105,106, 107]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[7], [102, 103]);
		$this->assertEquals($result[8], [105, 106, 107]);
		// Case when new faces are added to new cluster
		//
		$old = array(3=>[110,111], 4=>[112,113]);
		$new = array(1=>[110,111], 2=>[112,113], 3=>[114, 115, 116]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 3);
		$this->assertEquals($result[3], [110, 111]);
		$this->assertEquals($result[4], [112, 113]);
		$this->assertEquals($result[5], [114, 115, 116]);
		// Case when existing face "pops" to new cluster (cluster split)
		//
		$old = array(5=>[110,111,112], 6=>[113,114]);
		$new = array(1=>[110,111], 2=>[113,114], 3=>[112]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 3);
		$this->assertEquals($result[5], [110,111]);
		$this->assertEquals($result[6], [113, 114]);
		$this->assertEquals($result[7], [112]);
		// Case when existing face is removed
		//
		$old = array(7=>[110,111], 8=>[113,114]);
		$new = array(1=>[110], 2=>[113,114]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[7], [110]);
		$this->assertEquals($result[8], [113, 114]);
		// Case when all faces in cluster are removed (cluster dissapear)
		//
		$old = array(3=>[110,111], 4=>[113,114]);
		$new = array(1=>[110,111]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 1);
		$this->assertEquals($result[3], [110, 111]);
		// Case when existing faces move to other cluster (cluster spil)
		//
		$old = array(5=>[110,111], 6=>[112,113,114]);
		$new = array(1=>[110,111,112,113], 2=>[114]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[5], [110, 111,112,113]);
		$this->assertEquals($result[6], [114]);
	}

	/**
	 * More complex case demostrating various use cases
	 */
	public function testMergeClustersComplex() {
		// Case when old cluster is empty and we get some new clusters
		//
		$old = array(
			10=>[100,101,102,103],
			11=>[104,105,106,107],
			12=>[108,109,110,111],
			13=>[112,113,114,115]
		);
		$new = array(
			1=>[100,101,102,103], // not touched
			2=>[104,105,106,107,130], // new face added to this one
			3=>[108,109,110], // one face removed
			4=>[112,113,114], // one face moved to separate cluster
			5=>[115,116], // face from cluster 4 (12) plus new face in this
			6=>[117,118,119] // completely new cluster with new faces
		);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 6);
		$this->assertEquals($result[10], [100, 101, 102, 103]);
		$this->assertEquals($result[11], [104, 105, 106, 107, 130]);
		$this->assertEquals($result[12], [108, 109, 110]);
		$this->assertEquals($result[13], [112, 113, 114]);
		$this->assertEquals($result[14], [115, 116]);
		$this->assertEquals($result[15], [117, 118, 119]);
	}

	/**
	 * Passing an empty manual-face map must not change the legacy behavior:
	 * the majority vote still decides the mapping.
	 */
	public function testMergeClustersWithoutManualMapIsUnchanged() {
		$old = array(10=>[101, 102, 103]);
		$new = array(1=>[101, 102, 103, 104]);
		// Same result with no third argument and with an explicit empty map.
		$this->assertEquals(
			$this->createClusterTask->mergeClusters($old, $new),
			$this->createClusterTask->mergeClusters($old, $new, [])
		);
		$result = $this->createClusterTask->mergeClusters($old, $new, []);
		$this->assertEquals(count($result), 1);
		$this->assertEquals($result[10], [101, 102, 103, 104]);
	}

	/**
	 * A manual face must keep its own person even when the cluster it lands in is
	 * dominated by another (named) person. Without anchoring the majority vote
	 * would move the manual face to person 10 and drop person 20 (the user-set
	 * name). Face 201 is the manual face pinned to person 20.
	 */
	public function testMergeClustersAnchorsManualFaceOverMajority() {
		$old = array(10=>[101, 102, 103], 20=>[201]);
		// Chinese whispers grouped the manual face 201 together with person 10's faces.
		$new = array(1=>[101, 102, 103, 201]);

		// Legacy behavior: majority wins, manual face stolen by person 10.
		$legacy = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals($legacy[10], [101, 102, 103, 201]);
		$this->assertArrayNotHasKey(20, $legacy);

		// Anchored: the whole cluster is forced onto the manual person 20, so the
		// user-set name survives and the matching faces inherit it.
		$result = $this->createClusterTask->mergeClusters($old, $new, [201 => 20]);
		$this->assertEquals(count($result), 1);
		$this->assertEquals($result[20], [101, 102, 103, 201]);
		$this->assertArrayNotHasKey(10, $result);
	}

	/**
	 * A manual face that would otherwise be split off into a brand-new, unnamed
	 * cluster must instead stay with its user-set person. Here face 201 (manual,
	 * person 20) clusters with a previously unassigned face 301; the legacy merge
	 * creates a new null-name person for them.
	 */
	public function testMergeClustersAnchorsManualFaceInNewCluster() {
		// Faces 301 and 302 were previously unassigned (no person), so they
		// outnumber the manual face and the legacy merge spawns a fresh,
		// unnamed person (id 21) for the whole group, detached from person 20.
		$old = array(20=>[201]);
		$new = array(1=>[201, 301, 302]);

		$legacy = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertArrayNotHasKey(20, $legacy);
		$this->assertEquals($legacy[21], [201, 301, 302]);

		// Anchored: face 201 keeps person 20 and the matching faces inherit it.
		$result = $this->createClusterTask->mergeClusters($old, $new, [201 => 20]);
		$this->assertEquals(count($result), 1);
		$this->assertEquals($result[20], [201, 301, 302]);
	}

	/**
	 * When two manual faces pinned to different persons land in the same cluster,
	 * each must keep its own person (neither name may be lost). Non-manual faces
	 * follow the dominant manual person (ties broken by lowest person ID).
	 */
	public function testMergeClustersAnchorsConflictingManualFaces() {
		$old = array(20=>[201], 30=>[202], 40=>[301, 302]);
		// All grouped together by clustering.
		$new = array(1=>[201, 202, 301, 302]);

		$result = $this->createClusterTask->mergeClusters($old, $new, [201 => 20, 202 => 30]);

		// Each manual face keeps its own person.
		$this->assertContains(201, $result[20]);
		$this->assertContains(202, $result[30]);
		// Manual faces are not mixed into the other person.
		$this->assertNotContains(202, $result[20]);
		$this->assertNotContains(201, $result[30]);
		// Auto faces follow the dominant manual person (tie -> lowest id 20).
		$this->assertContains(301, $result[20]);
		$this->assertContains(302, $result[20]);
		// Every face is accounted for exactly once.
		$allFaces = array_merge(...array_values($result));
		sort($allFaces);
		$this->assertEquals([201, 202, 301, 302], $allFaces);
	}

	/**
	 * Manual face ids that are not part of the current clustering run (they never
	 * appear in any new cluster) must have no effect on the result.
	 */
	public function testMergeClustersIgnoresManualFacesNotInClustering() {
		$old = array(10=>[101, 102]);
		$new = array(1=>[101, 102]);
		// 999 is a manual face that is not part of this run.
		$result = $this->createClusterTask->mergeClusters($old, $new, [999 => 20]);
		$this->assertEquals(count($result), 1);
		$this->assertEquals($result[10], [101, 102]);
		$this->assertArrayNotHasKey(20, $result);
	}

	/**
	 * With a person-name map provided, an anchor must NOT pull in a face that
	 * already belongs to a DIFFERENTLY named person. That named group stays intact
	 * (not relabeled, not emptied and later deleted) — the manual mark simply does
	 * not claim it, even though clustering grouped them together.
	 */
	public function testMergeClustersAnchorDoesNotAbsorbDifferentNamedPerson() {
		// Person 20 = "Alice" (manual anchor, face 201); person 30 = "Bob" (301, 302).
		$old = array(20=>[201], 30=>[301, 302]);
		// Clustering grouped Alice's manual face together with Bob's faces.
		$new = array(1=>[201, 301, 302]);
		$manual = array(201 => 20);
		$names  = array(20 => 'Alice', 30 => 'Bob');

		$result = $this->createClusterTask->mergeClusters($old, $new, $manual, $names);

		// Alice keeps only her own face; Bob's group is left completely untouched.
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[20], [201]);
		$this->assertEquals($result[30], [301, 302]);
	}

	/**
	 * The anchor still claims faces that are unnamed (no person yet) and faces that
	 * already belong to a person with the SAME name. Face 301 is unassigned and
	 * person 25 shares the anchor's name "Alice".
	 */
	public function testMergeClustersAnchorClaimsUnnamedAndSameName() {
		$old = array(20=>[201], 25=>[251]);
		$new = array(1=>[201, 251, 301]);
		$manual = array(201 => 20);
		$names  = array(20 => 'Alice', 25 => 'Alice');

		$result = $this->createClusterTask->mergeClusters($old, $new, $manual, $names);

		// All three faces consolidate onto the anchor "Alice" (person 20).
		$this->assertEquals(count($result), 1);
		$faces = $result[20];
		sort($faces);
		$this->assertEquals($faces, [201, 251, 301]);
	}

	/**
	 * Mixed cluster: the anchor claims the unnamed face and the same-name face but
	 * leaves the differently-named face with its original person.
	 */
	public function testMergeClustersAnchorMixedClaimAndKeep() {
		// 20 = "Alice" (manual, 201); 25 = "Alice" (251); 30 = "Bob" (301); 401 unassigned.
		$old = array(20=>[201], 25=>[251], 30=>[301]);
		$new = array(1=>[201, 251, 301, 401]);
		$manual = array(201 => 20);
		$names  = array(20 => 'Alice', 25 => 'Alice', 30 => 'Bob');

		$result = $this->createClusterTask->mergeClusters($old, $new, $manual, $names);

		// Alice (anchor) gains the same-name face 251 and the unnamed face 401.
		$aliceFaces = $result[20];
		sort($aliceFaces);
		$this->assertEquals($aliceFaces, [201, 251, 401]);
		// Bob is left intact, person 25 (same name) was absorbed into the anchor.
		$this->assertEquals($result[30], [301]);
		$this->assertArrayNotHasKey(25, $result);
		$this->assertEquals(count($result), 2);
	}

	/**
	 * An unnamed cluster (a person row whose name is null) is treated like
	 * unassigned faces: the anchor may claim it.
	 */
	public function testMergeClustersAnchorClaimsUnnamedCluster() {
		// 20 = "Alice" (manual, 201); 50 = unnamed cluster (name null, faces 501, 502).
		$old = array(20=>[201], 50=>[501, 502]);
		$new = array(1=>[201, 501, 502]);
		$manual = array(201 => 20);
		$names  = array(20 => 'Alice', 50 => null);

		$result = $this->createClusterTask->mergeClusters($old, $new, $manual, $names);

		$this->assertEquals(count($result), 1);
		$faces = $result[20];
		sort($faces);
		$this->assertEquals($faces, [201, 501, 502]);
	}

	/**
	 * Two manual anchors with different names in one cluster: each keeps its own
	 * face, and a third face that belongs to yet another named person is left with
	 * that person rather than being pulled onto the dominant anchor.
	 */
	public function testMergeClustersConflictingAnchorsKeepDifferentNamedFace() {
		// 20 = "Alice" (manual 201); 30 = "Bob" (manual 202); 40 = "Carol" (auto 401).
		$old = array(20=>[201], 30=>[202], 40=>[401]);
		$new = array(1=>[201, 202, 401]);
		$manual = array(201 => 20, 202 => 30);
		$names  = array(20 => 'Alice', 30 => 'Bob', 40 => 'Carol');

		$result = $this->createClusterTask->mergeClusters($old, $new, $manual, $names);

		// Each manual face keeps its own person; Carol's face is left with Carol.
		$this->assertEquals($result[20], [201]);
		$this->assertEquals($result[30], [202]);
		$this->assertEquals($result[40], [401]);
		$this->assertEquals(count($result), 3);
	}
}
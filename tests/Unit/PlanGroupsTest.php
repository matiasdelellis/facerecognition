<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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

use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;

/**
 * Tests how the groups found by the clustering are read back into clusters.
 *
 * The convention of the fixtures is that IDs below 100 are clusters and IDs
 * above 100 are faces, to make them easier to read.
 */
class PlanGroupsTest extends TestCase {

	/**
	 * A group with no sampled face in it is a cluster that did not exist.
	 */
	public function testNewCluster() {
		$plan = CreateClustersTask::planGroups(
			[[101, 102, 103]],
			[],
			[],
			[]);

		$this->assertEquals([[101, 102, 103]], $plan['create']);
		$this->assertEquals([], $plan['attach']);
		$this->assertEquals([], $plan['absorb']);
	}

	/**
	 * A group with the sample of one cluster hands it the new faces, and the
	 * sampled face itself is not touched.
	 */
	public function testJoinExistingCluster() {
		$plan = CreateClustersTask::planGroups(
			[[101, 102, 110]],
			[110 => 7],
			[7 => 42],
			[7 => ['person' => null, 'is_visible' => true]]);

		$this->assertEquals([7 => [101, 102]], $plan['attach']);
		$this->assertEquals([], $plan['create']);
		$this->assertEquals([], $plan['absorb']);
	}

	/**
	 * Several groups at once, each one with its own outcome.
	 */
	public function testSeveralGroups() {
		$plan = CreateClustersTask::planGroups(
			[[110, 101], [111, 102, 103], [104]],
			[110 => 7, 111 => 8],
			[7 => 2, 8 => 3],
			[]);

		$this->assertEquals([7 => [101], 8 => [102, 103]], $plan['attach']);
		$this->assertEquals([[104]], $plan['create']);
		$this->assertEquals([], $plan['absorb']);
	}

	/**
	 * Nothing happens to a cluster whose sample was grouped alone.
	 */
	public function testUntouchedCluster() {
		$plan = CreateClustersTask::planGroups(
			[[110], [111, 112]],
			[110 => 7, 111 => 8, 112 => 8],
			[7 => 2, 8 => 3],
			[]);

		$this->assertEquals([], $plan['attach']);
		$this->assertEquals([], $plan['create']);
		$this->assertEquals([], $plan['absorb']);
	}

	/**
	 * Samples of two clusters in one group means they are the same: the biggest
	 * one absorbs the other, and the new faces go to it.
	 */
	public function testBiggestClusterAbsorbs() {
		$plan = CreateClustersTask::planGroups(
			[[110, 111, 101]],
			[110 => 7, 111 => 8],
			[7 => 3, 8 => 25],
			[]);

		$this->assertEquals([8 => [7]], $plan['absorb']);
		$this->assertEquals([8 => [101]], $plan['attach']);
	}

	/**
	 * With the same size, the oldest cluster survives, so that the result does
	 * not depend on the order the groups came in.
	 */
	public function testOldestClusterSurvivesATie() {
		$plan = CreateClustersTask::planGroups(
			[[111, 110]],
			[110 => 7, 111 => 8],
			[7 => 5, 8 => 5],
			[]);

		$this->assertEquals([7 => [8]], $plan['absorb']);
	}

	/**
	 * A cluster that belongs to a person survives even if it is the smallest
	 * one, and the person reaches the faces of the cluster it absorbs.
	 */
	public function testClusterOfAPersonSurvives() {
		$plan = CreateClustersTask::planGroups(
			[[110, 111, 101]],
			[110 => 7, 111 => 8],
			[7 => 3, 8 => 25],
			[7 => ['person' => 11, 'is_visible' => true]]);

		$this->assertEquals([7 => [8]], $plan['absorb']);
		$this->assertEquals([7 => [101]], $plan['attach']);
	}

	/**
	 * Two clusters of two different people are two decisions of the user:
	 * neither is absorbed, and the new faces join the biggest of them.
	 */
	public function testClustersOfTwoPeopleAreNotMerged() {
		$plan = CreateClustersTask::planGroups(
			[[110, 111, 101]],
			[110 => 7, 111 => 8],
			[7 => 3, 8 => 25],
			[7 => ['person' => 11, 'is_visible' => true],
			 8 => ['person' => 12, 'is_visible' => true]]);

		$this->assertEquals([], $plan['absorb']);
		$this->assertEquals([8 => [101]], $plan['attach']);
	}

	/**
	 * A cluster the user hid is not absorbed either, and does not absorb the
	 * visible ones.
	 */
	public function testHiddenClusterIsNotMerged() {
		$plan = CreateClustersTask::planGroups(
			[[110, 111, 101]],
			[110 => 7, 111 => 8],
			[7 => 30, 8 => 4],
			[7 => ['person' => null, 'is_visible' => false],
			 8 => ['person' => null, 'is_visible' => true]]);

		$this->assertEquals([], $plan['absorb']);
		$this->assertEquals([8 => [101]], $plan['attach']);
	}

	/**
	 * Three clusters in one group collapse into one.
	 */
	public function testThreeClustersInOneGroup() {
		$plan = CreateClustersTask::planGroups(
			[[110, 111, 112, 101]],
			[110 => 7, 111 => 8, 112 => 9],
			[7 => 2, 8 => 30, 9 => 5],
			[]);

		$this->assertEquals([8], array_keys($plan['absorb']));
		$absorbed = $plan['absorb'][8];
		sort($absorbed);
		$this->assertEquals([7, 9], $absorbed);
		$this->assertEquals([8 => [101]], $plan['attach']);
	}

	/**
	 * The samples of one cluster can fall in different groups, and a cluster
	 * absorbed by one of them must not be handed faces by another.
	 */
	public function testFacesFollowAnAbsorbedCluster() {
		$plan = CreateClustersTask::planGroups(
			[[110, 111, 101], [112, 102]],
			[110 => 7, 111 => 8, 112 => 7],
			[7 => 3, 8 => 25],
			[]);

		$this->assertEquals([8 => [7]], $plan['absorb']);
		// 101 and 102 both end up in the cluster that survived
		$this->assertArrayNotHasKey(7, $plan['attach']);
		$this->assertEquals([101, 102], $plan['attach'][8]);
	}

	/**
	 * And the other way around: when the cluster is absorbed after it was
	 * already given faces, those faces move with it.
	 */
	public function testFacesGivenBeforeTheMerge() {
		$plan = CreateClustersTask::planGroups(
			[[112, 102], [110, 111, 101]],
			[110 => 7, 111 => 8, 112 => 7],
			[7 => 3, 8 => 25],
			[]);

		$this->assertEquals([8 => [7]], $plan['absorb']);
		$this->assertArrayNotHasKey(7, $plan['attach']);
		$this->assertEquals([102, 101], $plan['attach'][8]);
	}
}

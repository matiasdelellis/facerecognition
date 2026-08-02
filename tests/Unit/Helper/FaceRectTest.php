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

use OCA\FaceRecognition\Helper\FaceRect;

use Test\TestCase;

class FaceRectTest extends TestCase {

	public function testSomeOverlaps() {
		$rectA = [];
		$rectA['left'] = 10;
		$rectA['right'] = 20;
		$rectA['top'] = 10;
		$rectA['bottom'] = 20;

		$rectB = [];
		$rectB['left'] = 10;
		$rectB['right'] = 20;
		$rectB['top'] = 10;
		$rectB['bottom'] = 20;
		$this->assertEquals(FaceRect::overlapPercent($rectA, $rectB), 1.0);

		$rectB['left'] = 25;
		$rectB['right'] = 35;
		$rectB['top'] = 10;
		$rectB['bottom'] = 20;
		$this->assertEquals(FaceRect::overlapPercent($rectA, $rectB), 0.0);

		$rectB['left'] = 15;
		$rectB['right'] = 25;
		$rectB['top'] = 10;
		$rectB['bottom'] = 20;
		$this->assertEqualsWithDelta(FaceRect::overlapPercent($rectA, $rectB), 0.33, 0.01);
	}

	private function oldFace($id, $left, $top, $width, $height, $cluster, $groupable = true) {
		return [
			'left' => $left,
			'right' => $left + $width,
			'top' => $top,
			'bottom' => $top + $height,
			'cluster' => $cluster,
			'is_groupable' => $groupable,
		];
	}

	private function newFace($left, $top, $width, $height) {
		return [
			'left' => $left,
			'right' => $left + $width,
			'top' => $top,
			'bottom' => $top + $height,
		];
	}

	/**
	 * A face found again in the same place keeps its cluster.
	 */
	public function testMatchKeepsCluster() {
		$old = [0 => $this->oldFace(0, 10, 10, 80, 80, 5)];
		$new = [0 => $this->newFace(10, 10, 80, 80)];
		$this->assertEquals([0 => ['cluster' => 5, 'is_groupable' => true]], FaceRect::matchClusters($new, $old));
	}

	/**
	 * A face with barely any overlap is not trusted, so it is left to be
	 * clustered on its own.
	 */
	public function testNoOverlapIsNotMatched() {
		$old = [0 => $this->oldFace(0, 10, 10, 80, 80, 5)];
		$new = [0 => $this->newFace(200, 200, 80, 80)];
		$this->assertEquals([], FaceRect::matchClusters($new, $old));
	}

	/**
	 * A face that the user detached keeps its solo cluster and its
	 * non-groupable state, so the refinement does not put it back where the
	 * user took it out of.
	 */
	public function testDetachedOldFaceKeepsClusterAndIsGroupableFalse() {
		$old = [0 => $this->oldFace(0, 10, 10, 80, 80, 5, false)];
		$new = [0 => $this->newFace(10, 10, 80, 80)];
		$this->assertEquals([0 => ['cluster' => 5, 'is_groupable' => false]], FaceRect::matchClusters($new, $old));
	}

	/**
	 * An old face without a cluster has nothing to inherit.
	 */
	public function testOldFaceWithoutClusterIsNotInherited() {
		$old = [0 => $this->oldFace(0, 10, 10, 80, 80, null)];
		$new = [0 => $this->newFace(10, 10, 80, 80)];
		$this->assertEquals([], FaceRect::matchClusters($new, $old));
	}

	/**
	 * Two new faces cannot both take the same old face: the first one in the
	 * input order wins, the other is left to be clustered on its own.
	 */
	public function testGreedyOneToOne() {
		$old = [0 => $this->oldFace(0, 10, 10, 80, 80, 7)];
		$new = [
			0 => $this->newFace(10, 10, 80, 80),
			1 => $this->newFace(10, 10, 80, 80),
		];
		$assigned = FaceRect::matchClusters($new, $old);
		$this->assertEquals([0 => ['cluster' => 7, 'is_groupable' => true]], $assigned);
	}

	/**
	 * Two faces at two different places each keep their own cluster, and their
	 * own groupability.
	 */
	public function testTwoMatches() {
		$old = [
			0 => $this->oldFace(0, 10, 10, 80, 80, 3),
			1 => $this->oldFace(1, 200, 200, 80, 80, 9),
		];
		$new = [
			0 => $this->newFace(10, 10, 80, 80),
			1 => $this->newFace(200, 200, 80, 80),
		];
		$assigned = FaceRect::matchClusters($new, $old);
		$this->assertEquals([
			0 => ['cluster' => 3, 'is_groupable' => true],
			1 => ['cluster' => 9, 'is_groupable' => true],
		], $assigned);
	}

	/**
	 * A grouped and a detached face in the same image: each new face keeps the
	 * cluster of the old one, and the detached one keeps its non-groupable
	 * state.
	 */
	public function testGroupedAndDetachedMixed() {
		$old = [
			0 => $this->oldFace(0, 10, 10, 80, 80, 3),
			1 => $this->oldFace(1, 200, 200, 80, 80, 9, false),
		];
		$new = [
			0 => $this->newFace(10, 10, 80, 80),
			1 => $this->newFace(200, 200, 80, 80),
		];
		$assigned = FaceRect::matchClusters($new, $old);
		$this->assertEquals([
			0 => ['cluster' => 3, 'is_groupable' => true],
			1 => ['cluster' => 9, 'is_groupable' => false],
		], $assigned);
	}

}

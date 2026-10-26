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
namespace OCA\FaceRecognition\Tests\Unit\Db;

use OCA\FaceRecognition\Db\Face;

use Test\TestCase;

class FaceTest extends TestCase {

	/**
	 * A face with a descriptor can be compared and grouped with the others.
	 */
	public function testFaceWithDescriptorIsGroupable() {
		$face = Face::fromModel(1, [
			'left' => 10,
			'top' => 20,
			'right' => 60,
			'bottom' => 80,
			'detection_confidence' => 0.99,
			'landmarks' => [],
			'descriptor' => [0.1, 0.2],
		]);

		$this->assertTrue($face->getIsGroupable());
	}

	/**
	 * Without a descriptor there is nothing to compare, so the face must not
	 * be put in a group with the first cluster it is compared with.
	 */
	public function testFaceWithoutDescriptorIsNotGroupable() {
		$face = Face::fromModel(1, [
			'left' => 10,
			'top' => 20,
			'right' => 60,
			'bottom' => 80,
			'detection_confidence' => 0.99,
		]);

		$this->assertFalse($face->getIsGroupable());
	}

	/**
	 * The missing landmarks and descriptor are normalized to empty arrays.
	 */
	public function testMissingLandmarksAndDescriptorBecomeArrays() {
		$face = Face::fromModel(1, [
			'left' => 10,
			'top' => 20,
			'right' => 60,
			'bottom' => 80,
			'detection_confidence' => 0.99,
		]);

		$this->assertEquals([], $face->landmarks);
		$this->assertEquals([], $face->descriptor);
		$this->assertEquals('[]', $face->getLandmarks());
	}
}

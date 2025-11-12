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
namespace OCA\FaceRecognition\Tests\Unit\Helpers;

use OCA\FaceRecognition\Helper\FaceRect;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FaceRect::class)]
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

}
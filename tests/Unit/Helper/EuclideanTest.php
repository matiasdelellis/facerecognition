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

use OCA\FaceRecognition\Helper\Euclidean;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Euclidean::class)]
class EuclideanTest extends TestCase {

	public function testEuclideans() {
		$this->assertEquals(Euclidean::distance([0.0, 0.0], [0.0, 1.0]), 1.0);
		$this->assertEquals(Euclidean::distance([0.0, 0.0, -1.0], [0.0, 0.0, 1.0]), 2.0);
		$this->assertEquals(Euclidean::distance([0.0, 2.5, 1.0], [0.0, 1.0, 1.0]), 1.5);
	}

}
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

use OCA\FaceRecognition\Helper\MemoryLimits;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MemoryLimits::class)]
class MemoryLimitsTest extends TestCase {

	public function testReturnBytes() {
		$this->assertEquals(0, MemoryLimits::returnBytes(''));
		$this->assertEquals(0, MemoryLimits::returnBytes('foo'));
		$this->assertEquals(0, MemoryLimits::returnBytes('foo100'));
		$this->assertEquals(100, MemoryLimits::returnBytes('100'));
		$this->assertEquals(100, MemoryLimits::returnBytes('100fgh'));
		$this->assertEquals(101 * 1024, MemoryLimits::returnBytes('101K'));
		$this->assertEquals(102 * 1024 * 1024, MemoryLimits::returnBytes('102M'));
		$this->assertEquals(103 * 1024 * 1024 * 1024, MemoryLimits::returnBytes('103g'));
	}
}
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
namespace OCA\FaceRecognition\Tests\Unit;

use OCA\FaceRecognition\BackgroundJob\WorkerConfig;

use Test\TestCase;

class WorkerConfigTest extends TestCase {

	public function testJsonRoundTrip() {
		$config = new WorkerConfig(2, 4, 'default-mode');
		$parsed = WorkerConfig::fromJson(json_decode(json_encode($config), true));

		$this->assertEquals(2, $parsed->getWorkerIndex());
		$this->assertEquals(4, $parsed->getWorkerCount());
		$this->assertEquals('default-mode', $parsed->getMode());
	}

	public function testJsonRoundTripFirstWorker() {
		$config = new WorkerConfig(0, 1, 'defer-mode');
		$parsed = WorkerConfig::fromJson(json_decode(json_encode($config), true));

		$this->assertEquals(0, $parsed->getWorkerIndex());
		$this->assertEquals(1, $parsed->getWorkerCount());
		$this->assertEquals('defer-mode', $parsed->getMode());
	}

	public function testJsonRoundTripFastMode() {
		$config = new WorkerConfig(3, 4, 'fast-mode');
		$parsed = WorkerConfig::fromJson(json_decode(json_encode($config), true));

		$this->assertEquals(3, $parsed->getWorkerIndex());
		$this->assertEquals(4, $parsed->getWorkerCount());
		$this->assertEquals('fast-mode', $parsed->getMode());
	}

	public function testMissingMode() {
		$this->expectException(\InvalidArgumentException::class);
		WorkerConfig::fromJson(['workerIndex' => 0, 'workerCount' => 1]);
	}

	public function testMissingWorkerIndex() {
		$this->expectException(\InvalidArgumentException::class);
		WorkerConfig::fromJson(['workerCount' => 2]);
	}

	public function testMissingWorkerCount() {
		$this->expectException(\InvalidArgumentException::class);
		WorkerConfig::fromJson(['workerIndex' => 1]);
	}

	public function testInvalidMode() {
		$this->expectException(\InvalidArgumentException::class);
		WorkerConfig::fromJson(['workerIndex' => 0, 'workerCount' => 1, 'mode' => 42]);
	}

	public function testInvalidData() {
		$this->expectException(\InvalidArgumentException::class);
		WorkerConfig::fromJson([]);
	}
}

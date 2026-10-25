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

use OCA\FaceRecognition\Helper\Distance;
use OCA\FaceRecognition\Helper\Euclidean;

use Test\TestCase;

class DistanceTest extends TestCase {

	private function cosineDistance(array $a, array $b): float {
		$dot = $na = $nb = 0.0;
		foreach ($a as $i => $value) {
			$dot += $value * $b[$i];
			$na += $value * $value;
			$nb += $b[$i] * $b[$i];
		}
		return 1.0 - $dot / sqrt($na * $nb);
	}

	public function testEuclideanIsLeftAsIs() {
		$this->assertEquals([3.0, 4.0], Distance::prepare([3.0, 4.0], Distance::EUCLIDEAN));
		$this->assertEquals(0.4, Distance::toEuclidean(0.4, Distance::EUCLIDEAN));
		$this->assertEquals(0.4, Distance::fromEuclidean(0.4, Distance::EUCLIDEAN));
	}

	public function testCosineDescriptorsAreNormalized() {
		$this->assertEqualsWithDelta([0.6, 0.8], Distance::prepare([3.0, 4.0], Distance::COSINE), 1e-12);
		// Nothing to normalize, but it does not divide by zero.
		$this->assertEquals([0.0, 0.0], Distance::prepare([0.0, 0.0], Distance::COSINE));
	}

	/**
	 * The Euclidean distance between normalized descriptors, translated back,
	 * is the cosine distance, whatever their length was.
	 */
	public function testCosineThroughEuclidean() {
		$pairs = [
			[[1.0, 0.0], [0.0, 2.0]],
			[[1.0, 2.0, 3.0], [-2.0, 0.5, 4.0]],
			[[0.3, -0.1, 0.7, 0.2], [0.25, -0.05, 0.9, 0.1]],
			[[1.0, 1.0], [-3.0, -3.0]],
		];
		foreach ($pairs as [$a, $b]) {
			$euclidean = Euclidean::distance(Distance::prepare($a, Distance::COSINE), Distance::prepare($b, Distance::COSINE));
			$this->assertEqualsWithDelta($this->cosineDistance($a, $b), Distance::fromEuclidean($euclidean, Distance::COSINE), 1e-12);
		}
	}

	public function testCosineThresholdRoundTrip() {
		foreach ([0.0, 0.2, 0.5, 1.0, 2.0] as $threshold) {
			$euclidean = Distance::toEuclidean($threshold, Distance::COSINE);
			$this->assertEqualsWithDelta($threshold, Distance::fromEuclidean($euclidean, Distance::COSINE), 1e-12);
		}
		$this->assertEqualsWithDelta(1.0, Distance::toEuclidean(0.5, Distance::COSINE), 1e-12);
	}

	public function testValidMetrics() {
		$this->assertTrue(Distance::isValid(Distance::EUCLIDEAN));
		$this->assertTrue(Distance::isValid(Distance::COSINE));
		$this->assertFalse(Distance::isValid('manhattan'));
	}

}

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

namespace OCA\FaceRecognition\Helper;

/**
 * The metric used to compare the descriptors of a model.
 *
 * The dlib models are compared with the Euclidean distance. Most of the newer
 * models (ArcFace, EdgeFace, SFace...) are trained to be compared with the
 * cosine distance (1 - cosine similarity), and their thresholds only make sense
 * with it.
 *
 * Both are computed with the Euclidean distance, which pdlib does fast: once
 * the descriptors are scaled to unit length, the cosine distance is d²/2 of the
 * Euclidean one. So a cosine model only has to normalize its descriptors and
 * translate the threshold.
 */
class Distance {

	const EUCLIDEAN = 'euclidean';
	const COSINE = 'cosine';

	public static function isValid(string $metric): bool {
		return in_array($metric, [self::EUCLIDEAN, self::COSINE], true);
	}

	/**
	 * Descriptor ready to be compared with the Euclidean distance.
	 */
	public static function prepare(array $descriptor, string $metric): array {
		if ($metric !== self::COSINE) {
			return $descriptor;
		}

		$norm = 0.0;
		foreach ($descriptor as $value) {
			$norm += $value * $value;
		}
		$norm = sqrt($norm);
		if ($norm <= 0.0) {
			return $descriptor;
		}

		return array_map(function ($value) use ($norm) {
			return $value / $norm;
		}, array_values($descriptor));
	}

	/**
	 * Euclidean distance between two prepared descriptors that corresponds to
	 * the distance $distance of the metric.
	 */
	public static function toEuclidean(float $distance, string $metric): float {
		if ($metric !== self::COSINE) {
			return $distance;
		}
		return sqrt(2.0 * max($distance, 0.0));
	}

	/**
	 * Distance of the metric that corresponds to the Euclidean distance
	 * between two prepared descriptors.
	 */
	public static function fromEuclidean(float $distance, string $metric): float {
		if ($metric !== self::COSINE) {
			return $distance;
		}
		return $distance * $distance / 2.0;
	}

}

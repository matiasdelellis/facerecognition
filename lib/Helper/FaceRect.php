<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
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

class FaceRect {

	public static function overlapPercent(array $rectA, array $rectB): float {
		// Firts face rect
		$leftA = $rectA['left'];
		$rightA = $rectA['right'];
		$topA = $rectA['top'];
		$bottomA = $rectA['bottom'];

		// Face rect to compare
		$leftB = $rectB['left'];
		$rightB = $rectB['right'];
		$topB = $rectB['top'];
		$bottomB = $rectB['bottom'];

		// If one rectangle is on left side of other
		if ($leftA >= $rightB || $leftB >= $rightA)
			return 0.0;

		// If one rectangle is above other
		if ($topA >= $bottomB || $topB >= $bottomA)
			return 0.0;

		// Overlap area.
		$leftO = max($leftA, $leftB);
		$rightO = min($rightA, $rightB);
		$topO = max($topA, $topB);
		$bottomO = min($bottomA, $bottomB);

		// Calculate the areas of all the rectangles
		$areaA = ($rightA - $leftA) * ($bottomA - $topA);
		$areaB = ($rightB - $leftB) * ($bottomB - $topB);
		$overlapArea = ($rightO - $leftO) * ($bottomO - $topO);

		// Calculate and return the overlay percent.
		return floatval($overlapArea / ($areaA + $areaB - $overlapArea));
	}

}

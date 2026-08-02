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

	/**
	 * Greedily matches the faces found in the refinement pass to the ones that
	 * were found in the fast pass, and returns what each new face keeps.
	 *
	 * The descriptors of both passes are not comparable, but a face found again
	 * in the same place of the same image is the same person, so its cluster can
	 * be carried over. This is what keeps a person that appears in a single
	 * photo from losing its name when the image is re-processed.
	 *
	 * Each new face takes the old one it overlaps the most, and an old face is
	 * taken once, so two new faces cannot inherit the same cluster. A match
	 * below the minimum overlap is not trusted, and the face is left to be
	 * clustered on its own.
	 *
	 * A non-groupable old face is a face the user detached: it lives in a
	 * cluster of its own and must not be grouped again. The new face keeps that
	 * cluster and stays non-groupable, so the clustering does not put it back
	 * into the cluster it was taken out of. An old face without a cluster has
	 * nothing to inherit.
	 *
	 * @param array $newFaces Faces as 'left', 'right', 'top', 'bottom', in original image coordinates
	 * @param array $oldFaces Faces as 'left', 'right', 'top', 'bottom', with 'cluster' and 'is_groupable'
	 * @param float $minOverlap Minimum IoU below which a match is not trusted
	 *
	 * @return array [index of the new face => ['cluster' => id, 'is_groupable' => bool]]
	 */
	public static function matchClusters(array $newFaces, array $oldFaces, float $minOverlap = 0.35): array {
		$assigned = [];
		$used = [];

		foreach ($newFaces as $newIndex => $newFace) {
			$bestOld = null;
			$bestOverlap = $minOverlap;
			foreach ($oldFaces as $oldIndex => $oldFace) {
				if (isset($used[$oldIndex])) {
					continue;
				}
				if (is_null($oldFace['cluster'])) {
					continue;
				}
				$overlap = self::overlapPercent($newFace, $oldFace);
				if ($overlap >= $bestOverlap) {
					$bestOverlap = $overlap;
					$bestOld = $oldIndex;
				}
			}

			if (!is_null($bestOld)) {
				$used[$bestOld] = true;
				$oldFace = $oldFaces[$bestOld];
				$assigned[$newIndex] = [
					'cluster' => (int) $oldFace['cluster'],
					'is_groupable' => (bool) $oldFace['is_groupable'],
				];
			}
		}

		return $assigned;
	}

}

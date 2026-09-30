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
namespace OCA\FaceRecognition\Helper;

use OCA\FaceRecognition\Db\Face;

/**
 * Whether a face takes part in the clustering, and why not, as the clients are
 * told about it.
 *
 * It is derived every time from what the clustering itself looks at, and never
 * stored. The participation has a writer that knows nothing about markings,
 * ClusterMapper::detachFace(), which takes any face out of the clustering, so a
 * stored value would sooner or later contradict the database.
 */
class FaceParticipation {

	/** Takes part in the clustering */
	public const PARTICIPATING = 'participating';

	/** A marking whose search for a descriptor did not run yet */
	public const PENDING = 'pending';

	/** Left out of the clustering, for the reason given with it */
	public const EXCLUDED = 'excluded';

	/** Smaller than the minimum face size */
	public const REASON_TOO_SMALL = 'too_small';

	/** A face was found, but the detector gave it less than the minimum confidence */
	public const REASON_LOW_CONFIDENCE = 'low_confidence';

	/** The search found no face in the region the user marked */
	public const REASON_NO_FACE = 'no_face';

	/** The user took the face out of its group */
	public const REASON_DETACHED = 'detached';

	/** The user ignored the face: it is in a hidden group of its own */
	public const REASON_IGNORED = 'ignored';

	/** Found by the analysis of the photo, including the markings it confirmed later */
	public const ORIGIN_AUTO = 'auto';

	/** Marked by the user, or found in a region the user marked */
	public const ORIGIN_MANUAL = 'manual';

	/**
	 * The reasons are checked in the order the clustering would stumble on
	 * them: a marking whose search is pending or failed has nothing to compare
	 * yet, and a face that is not groupable is never looked at for its size or
	 * its confidence. is_groupable is false for a marking whose search failed
	 * as well, so the state is looked at before it: only a face that has a
	 * descriptor can have been detached by the user.
	 *
	 * @param string|null $manualState the state of the search of a marking, null for any other face
	 * @param bool|null $isGroupable as stored; anything but true is not groupable, like in the queries of the clustering
	 * @param bool $ignored whether the face is in a hidden group, which is what ignoring it does. It comes first, since it is the decision of the user.
	 *
	 * @return array{clustering: string, excludedReason: string|null}
	 */
	public static function derive(?string $manualState, ?bool $isGroupable, int $width, int $height, float $confidence, int $minFaceSize, float $minConfidence, bool $ignored = false): array {
		if ($ignored) {
			return ['clustering' => self::EXCLUDED, 'excludedReason' => self::REASON_IGNORED];
		}
		if ($manualState === Face::MANUAL_STATE_PENDING) {
			return ['clustering' => self::PENDING, 'excludedReason' => null];
		}
		if ($manualState === Face::MANUAL_STATE_NO_FACE) {
			return ['clustering' => self::EXCLUDED, 'excludedReason' => self::REASON_NO_FACE];
		}
		if ($isGroupable !== true) {
			return ['clustering' => self::EXCLUDED, 'excludedReason' => self::REASON_DETACHED];
		}

		$reason = self::qualityReason($width, $height, $confidence, $minFaceSize, $minConfidence);
		if (!is_null($reason)) {
			return ['clustering' => self::EXCLUDED, 'excludedReason' => $reason];
		}

		return ['clustering' => self::PARTICIPATING, 'excludedReason' => null];
	}

	/**
	 * Why a detected face would be left out of the clustering by the minimums
	 * the clustering applies, or null if it would not. A face that fails both
	 * is reported as too small.
	 */
	public static function qualityReason(int $width, int $height, float $confidence, int $minFaceSize, float $minConfidence): ?string {
		if ($width < $minFaceSize || $height < $minFaceSize) {
			return self::REASON_TOO_SMALL;
		}
		if ($confidence < $minConfidence) {
			return self::REASON_LOW_CONFIDENCE;
		}
		return null;
	}

	/**
	 * A marking the analysis confirmed carries what the analysis found from
	 * then on, so it counts as found by the analysis; is_manual is no help
	 * here, since a moved face carries it too, only to keep it from being
	 * replaced.
	 */
	public static function origin(?string $manualState): string {
		if (!is_null($manualState) && $manualState !== Face::MANUAL_STATE_CONFIRMED) {
			return self::ORIGIN_MANUAL;
		}
		return self::ORIGIN_AUTO;
	}
}

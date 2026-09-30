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

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Helper\FaceParticipation;

use Test\TestCase;

/**
 * Whether a face takes part in the clustering is derived from what the
 * clustering looks at, never stored, so it cannot disagree with it.
 */
class FaceParticipationTest extends TestCase {

	private const MIN_SIZE = 40;
	private const MIN_CONFIDENCE = 0.99;

	private function derive(?string $state, ?bool $groupable, int $size = 100, float $confidence = 1.0): array {
		$derived = FaceParticipation::derive($state, $groupable, $size, $size, $confidence, self::MIN_SIZE, self::MIN_CONFIDENCE);
		return [$derived['clustering'], $derived['excludedReason']];
	}

	/**
	 * @dataProvider combinationsProvider
	 */
	public function testDerivedParticipation(?string $state, ?bool $groupable, int $size, float $confidence, string $clustering, ?string $reason) {
		$this->assertEquals([$clustering, $reason], $this->derive($state, $groupable, $size, $confidence));
	}

	public static function combinationsProvider(): array {
		return [
			// A marking that waits takes no part yet, whatever it looks like:
			// it was drawn small and carries no confidence until the search.
			'pending' => [Face::MANUAL_STATE_PENDING, true, 20, 0.0, 'pending', null],
			'pending, detached before the search' => [Face::MANUAL_STATE_PENDING, false, 100, 0.0, 'pending', null],

			// A marking whose search found nothing is out for that reason, and
			// not because it is not groupable, which follows from it.
			'no face' => [Face::MANUAL_STATE_NO_FACE, false, 100, 0.0, 'excluded', 'no_face'],
			'no face, still groupable' => [Face::MANUAL_STATE_NO_FACE, true, 100, 1.0, 'excluded', 'no_face'],

			// Taken out of its group by the user.
			'detached face of the analysis' => [null, false, 100, 1.0, 'excluded', 'detached'],
			'detached after the search found it' => [Face::MANUAL_STATE_FOUND, false, 100, 1.0, 'excluded', 'detached'],
			'detached after the analysis confirmed it' => [Face::MANUAL_STATE_CONFIRMED, false, 100, 1.0, 'excluded', 'detached'],
			'unknown groupability counts as not groupable' => [null, null, 100, 1.0, 'excluded', 'detached'],

			// The minimums of the clustering, for every face alike.
			'too small' => [Face::MANUAL_STATE_FOUND, true, 39, 1.0, 'excluded', 'too_small'],
			'too small face of the analysis' => [null, true, 39, 1.0, 'excluded', 'too_small'],
			'low confidence' => [Face::MANUAL_STATE_FOUND, true, 100, 0.6, 'excluded', 'low_confidence'],
			'low confidence of the analysis' => [null, true, 100, 0.98, 'excluded', 'low_confidence'],
			'too small and low confidence' => [Face::MANUAL_STATE_FOUND, true, 20, 0.6, 'excluded', 'too_small'],

			// On the minimums themselves the face takes part, as in the queries.
			'exactly the minimums' => [Face::MANUAL_STATE_FOUND, true, 40, 0.99, 'participating', null],
			'found' => [Face::MANUAL_STATE_FOUND, true, 100, 1.0, 'participating', null],
			'confirmed' => [Face::MANUAL_STATE_CONFIRMED, true, 100, 1.0, 'participating', null],
			'face of the analysis' => [null, true, 100, 1.0, 'participating', null],
		];
	}

	/**
	 * A face is too small when either side is, as in the queries.
	 */
	public function testEitherSideTooSmallIsTooSmall() {
		$this->assertEquals(FaceParticipation::REASON_TOO_SMALL,
			FaceParticipation::qualityReason(39, 100, 1.0, self::MIN_SIZE, self::MIN_CONFIDENCE));
		$this->assertEquals(FaceParticipation::REASON_TOO_SMALL,
			FaceParticipation::qualityReason(100, 39, 1.0, self::MIN_SIZE, self::MIN_CONFIDENCE));
		$this->assertNull(FaceParticipation::qualityReason(40, 40, 0.99, self::MIN_SIZE, self::MIN_CONFIDENCE));
	}

	/**
	 * Only a marking is of manual origin; a marking the analysis confirmed
	 * counts as found by the analysis, and a moved face was found by it too.
	 */
	public function testOrigin() {
		$this->assertEquals('auto', FaceParticipation::origin(null));
		$this->assertEquals('manual', FaceParticipation::origin(Face::MANUAL_STATE_PENDING));
		$this->assertEquals('manual', FaceParticipation::origin(Face::MANUAL_STATE_FOUND));
		$this->assertEquals('manual', FaceParticipation::origin(Face::MANUAL_STATE_NO_FACE));
		$this->assertEquals('auto', FaceParticipation::origin(Face::MANUAL_STATE_CONFIRMED));
	}
}

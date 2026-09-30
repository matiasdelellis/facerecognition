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

use OCA\FaceRecognition\BackgroundJob\Tasks\ManualRegionTask;
use OCA\FaceRecognition\Helper\ManualFaceDetector;

use Test\TestCase;

class ManualFaceDetectorTest extends TestCase {

	/**
	 * The area the CNN model analyzes with 2 GB assigned to it, which is
	 * assignedMemory / 1024.
	 */
	private const ANALYSIS_AREA_2GB = 2 * 1024 * 1024 * 1024 / 1024;

	/**
	 * A region drawn around a group of faces keeps a fixed margin, and its crop
	 * stays well below the area the model analyzes, so it is still scaled up.
	 * The proportional margin of a single marking would have spent that area
	 * on the margin alone.
	 */
	public function testMarginOfALargeRegionKeepsItBelowTheAnalysisArea() {
		$region = ['x' => 2500, 'y' => 1500, 'width' => 1000, 'height' => 650];

		$crop = ManualFaceDetector::cropRect(6000, 4000, $region, ManualRegionTask::REGION_MARGIN, ManualRegionTask::REGION_MARGIN);

		$this->assertEquals(['x' => 2436, 'y' => 1436, 'width' => 1128, 'height' => 778], $crop);
		$this->assertLessThan(self::ANALYSIS_AREA_2GB, $crop['width'] * $crop['height']);

		$proportional = ManualFaceDetector::cropRect(6000, 4000, $region, 400, 260);
		$this->assertGreaterThan(self::ANALYSIS_AREA_2GB, $proportional['width'] * $proportional['height']);
	}

	/**
	 * A small marked face is scaled up until it reaches the target side, and
	 * no further, instead of filling the whole area of the model.
	 */
	public function testASmallMarkedFaceIsScaledUpToTheTargetSideOnly() {
		// A face of 100 pixels, with the margin of 40% a crop of 180 x 180.
		$area = ManualFaceDetector::analysisArea(180, 180, (int) self::ANALYSIS_AREA_2GB, 100);

		$this->assertEquals(360 * 360, $area, 'twice, so that the face is 200 pixels');
	}

	/**
	 * A tiny marked face is scaled up by at most MAX_UPSCALE, even though
	 * reaching the target side would take more.
	 */
	public function testATinyMarkedFaceIsScaledUpByAtMostTheMaximum() {
		// A face of 20 pixels, a crop of 36 x 36; the target side would need 10 times.
		$area = ManualFaceDetector::analysisArea(36, 36, (int) self::ANALYSIS_AREA_2GB, 20);

		$this->assertEquals(144 * 144, $area);
	}

	/**
	 * A marked face bigger than the target side is not scaled up at all.
	 */
	public function testABigMarkedFaceIsNotScaledUp() {
		$this->assertEquals(720 * 720, ManualFaceDetector::analysisArea(720, 720, (int) self::ANALYSIS_AREA_2GB, 400));
	}

	/**
	 * A region, whose faces have no known size, is scaled up by at most
	 * MAX_UPSCALE, and a large one is scaled down to the area of the model.
	 */
	public function testARegionIsScaledUpByAtMostTheMaximumAndDownToTheModel() {
		$this->assertEquals(400 * 300, ManualFaceDetector::analysisArea(100, 75, (int) self::ANALYSIS_AREA_2GB));
		$this->assertEquals((int) self::ANALYSIS_AREA_2GB, ManualFaceDetector::analysisArea(3128, 2128, (int) self::ANALYSIS_AREA_2GB));
	}

	/**
	 * A file that is there and readable has no reason not to be read.
	 */
	public function testAReadablePhotoHasNoReasonToFail() {
		$this->assertNull(ManualFaceDetector::unreadableReason(__DIR__ . '/../../assets/lenna.jpg'));
	}

	/**
	 * A file missing on the storage is named as missing, and not only as a
	 * photo that cannot be loaded.
	 */
	public function testAMissingFileIsNamedAsMissing() {
		$reason = ManualFaceDetector::unreadableReason(sys_get_temp_dir() . '/facerecognition-missing-' . uniqid() . '.jpg');
		$this->assertEquals('the file of the photo is missing on the storage', $reason);
	}

	/**
	 * A file without read permission is named as such. As root every file is
	 * readable, so this only runs as another user, like the web server.
	 */
	public function testAFileWithoutReadPermissionIsNamedAsNotReadable() {
		if (function_exists('posix_getuid') && posix_getuid() === 0) {
			$this->markTestSkipped('root can read any file; checked on the test instance as www-data instead');
		}
		$path = tempnam(sys_get_temp_dir(), 'facerecognition');
		chmod($path, 0000);
		try {
			$this->assertEquals('the file of the photo is not readable for the web server, check its permissions',
				ManualFaceDetector::unreadableReason($path));
		} finally {
			chmod($path, 0600);
			unlink($path);
		}
	}

	/**
	 * The margin is cut at the edges of the photo.
	 */
	public function testCropIsKeptInsideThePhoto() {
		$crop = ManualFaceDetector::cropRect(800, 600, ['x' => 10, 'y' => 550, 'width' => 100, 'height' => 50], 64, 64);
		$this->assertEquals(['x' => 0, 'y' => 486, 'width' => 174, 'height' => 114], $crop);
	}

	public function testRegionNotOnThePhotoHasNoCrop() {
		$this->assertNull(ManualFaceDetector::cropRect(800, 600, ['x' => 900, 'y' => 10, 'width' => 100, 'height' => 50], 64, 0));
		$this->assertNull(ManualFaceDetector::cropRect(800, 600, ['x' => 10, 'y' => 10, 'width' => 0, 'height' => 50], 64, 64));
	}

	/**
	 * A face found on the crop goes back to the pixels of the photo, box and
	 * landmarks alike, and keeps what the detector said about it.
	 */
	public function testFacesGoBackToThePixelsOfThePhoto() {
		$raw = [
			'left' => 10, 'top' => 20, 'right' => 110, 'bottom' => 220,
			'detection_confidence' => 0.6,
			'landmarks' => [['x' => 50, 'y' => 60]],
			'descriptor' => [0.1, 0.2],
		];

		$face = ManualFaceDetector::toOriginal($raw, 0.5, 100, 200);

		$this->assertEquals([
			'x' => 105, 'y' => 210, 'width' => 50, 'height' => 100,
			'confidence' => 0.6,
			'landmarks' => [['x' => 125, 'y' => 230]],
			'descriptor' => [0.1, 0.2],
		], $face);
	}
}

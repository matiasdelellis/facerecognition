<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
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

use OCP\Image as OCP_Image;

use OCA\FaceRecognition\Helper\TempImage;
use OCA\FaceRecognition\Helper\Imaginary;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TempImage::class)]
#[UsesClass(Imaginary::class)]
class TempImageTest extends TestCase {

	private $testFile = null;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		$this->testFile = \OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg';
	}

	public function testImageTest_DoubleScaling() {
		// Try image with double scaling up
		$tempImage = new TempImage($this->testFile,
		                           'image/png',
		                           158*158*4,
		                           100);

		$this->assertFalse($tempImage->getSkipped());
		$this->assertEquals(1/2, $tempImage->getRatio());

		$tempPath = $tempImage->getTempPath();
		$this->assertTrue(file_exists($tempPath));

		$image = new OCP_Image();
		$image->loadFromFile($tempPath);
		$this->assertEquals(158*2, imagesx($image->resource()));
		$this->assertEquals(158*2, imagesy($image->resource()));

		$tempImage->clean();
		$this->assertFalse(file_exists($tempPath));
	}

	public function testImageTest_NoChange() {
		// Try an tempImage that not need change
		$tempImage = new TempImage($this->testFile,
		                           'image/png',
		                           158*158,
		                           100);

		$this->assertFalse($tempImage->getSkipped());
		$this->assertEquals(1, $tempImage->getRatio());

		$tempPath = $tempImage->getTempPath();
		$this->assertTrue(file_exists($tempPath));

		$image = new OCP_Image();
		$image->loadFromFile($tempPath);
		$this->assertEquals(158, imagesx($image->resource()));
		$this->assertEquals(158, imagesy($image->resource()));

		$tempImage->clean();
		$this->assertFalse(file_exists($tempPath));
	}

	public function testImageTest_SmallerThanMin() {
		// Try a file smaller than the minimum
		$tempImage = new TempImage($this->testFile,
		                           'image/png',
		                           640*480,
		                           500);

		$this->assertTrue($tempImage->getSkipped());
		$this->assertEquals(-1.0, $tempImage->getRatio());
		$this->assertEquals(158, imagesx($tempImage->resource()));
		$this->assertEquals(158, imagesy($tempImage->resource()));
	}
}
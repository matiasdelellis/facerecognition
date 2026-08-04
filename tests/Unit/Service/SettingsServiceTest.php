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

use OCA\FaceRecognition\Helper\Imaginary;
use OCA\FaceRecognition\Service\SettingsService;

use OCP\IConfig;

use Test\TestCase;

class SettingsServiceTest extends TestCase {

	/**
	 * Config where the administrator did not force any extra mimetype and did
	 * not configure an Imaginary service.
	 */
	private function makeConfig(array $systemMimetypes = [], string $imaginaryUrl = 'invalid'): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')
		       ->with(SettingsService::SYSTEM_ENABLED_MIMETYPES, [])
		       ->willReturn($systemMimetypes);
		$config->method('getSystemValueString')
		       ->with(Imaginary::SYSTEM_URL, 'invalid')
		       ->willReturn($imaginaryUrl);
		return $config;
	}

	/**
	 * Build a SettingsService whose backend support is fixed to $formats, so
	 * the behavior of the mimetype gatekeeper does not depend on the image
	 * backend of the machine running the tests.
	 *
	 * @param string[] $formats names of the extended formats the backend decodes
	 */
	private function makeSettingsService(IConfig $config, array $formats = []): SettingsService {
		return new class($config, $formats) extends SettingsService {
			private $formats;

			public function __construct(IConfig $config, array $formats) {
				parent::__construct($config, null);
				$this->formats = $formats;
			}

			protected function getBackendSupportedFormats(): array {
				return $this->formats;
			}
		};
	}

	/**
	 * Every mimetype of every extended format, flattened.
	 *
	 * @return string[]
	 */
	private function allExtendedMimetypes(): array {
		return array_merge(...array_values(SettingsService::EXTENDED_MIMETYPES));
	}

	public function testBaseMimetypesAreAlwaysAllowed() {
		$service = $this->makeSettingsService($this->makeConfig());

		foreach (SettingsService::BASE_MIMETYPES as $mimetype) {
			$this->assertTrue($service->isAllowedMimetype($mimetype), $mimetype . ' should be allowed');
		}
	}

	public function testExtendedMimetypesAreRejectedWithoutBackendSupport() {
		$service = $this->makeSettingsService($this->makeConfig());

		foreach ($this->allExtendedMimetypes() as $mimetype) {
			$this->assertFalse($service->isAllowedMimetype($mimetype), $mimetype . ' should not be allowed without backend support');
		}
	}

	public function testExtendedMimetypesAreAllowedWithBackendSupport() {
		$formats = array_keys(SettingsService::EXTENDED_MIMETYPES);
		$service = $this->makeSettingsService($this->makeConfig(), $formats);

		foreach ($this->allExtendedMimetypes() as $mimetype) {
			$this->assertTrue($service->isAllowedMimetype($mimetype), $mimetype . ' should be allowed when the backend supports it');
		}
	}

	public function testOnlyTheSupportedFormatIsAllowed() {
		$service = $this->makeSettingsService($this->makeConfig(), ['HEIC']);

		foreach (SettingsService::EXTENDED_MIMETYPES['HEIC'] as $mimetype) {
			$this->assertTrue($service->isAllowedMimetype($mimetype), $mimetype . ' should be allowed');
		}
		foreach (['image/webp', 'image/tiff', 'image/avif', 'image/gif', 'image/bmp'] as $mimetype) {
			$this->assertFalse($service->isAllowedMimetype($mimetype), $mimetype . ' should not be allowed');
		}
	}

	/**
	 * Imaginary is built on libvips, that does not read BMP. Enabling it would
	 * only index files that fail later, when they are decoded.
	 */
	public function testImaginaryEnablesEveryExtendedFormatButBmp() {
		$config = $this->makeConfig([], 'http://localhost:9000');
		$service = new SettingsService($config, null);

		foreach (SettingsService::EXTENDED_MIMETYPES['BMP'] as $mimetype) {
			$this->assertFalse($service->isAllowedMimetype($mimetype), $mimetype . ' should not be allowed with Imaginary');
		}
		foreach (['image/gif', 'image/webp', 'image/heic', 'image/tiff', 'image/avif'] as $mimetype) {
			$this->assertTrue($service->isAllowedMimetype($mimetype), $mimetype . ' should be allowed with Imaginary');
		}
	}

	public function testAdministratorConfigIsAlwaysHonored() {
		$service = $this->makeSettingsService($this->makeConfig(['image/jxl']));

		$this->assertTrue($service->isAllowedMimetype('image/jxl'));
		$this->assertTrue($service->isAllowedMimetype('image/jpeg'));
	}
}

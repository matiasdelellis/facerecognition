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
use OCA\FaceRecognition\Helper\Imaginary;
use OCA\FaceRecognition\Model\ExternalModel\ExternalModel;
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
		foreach (['image/tiff', 'image/avif'] as $mimetype) {
			$this->assertFalse($service->isAllowedMimetype($mimetype), $mimetype . ' should not be allowed');
		}
	}

	public function testImaginaryEnablesEveryExtendedFormat() {
		$config = $this->makeConfig([], 'http://localhost:9000');
		$service = new SettingsService($config, null);

		foreach ($this->allExtendedMimetypes() as $mimetype) {
			$this->assertTrue($service->isAllowedMimetype($mimetype), $mimetype . ' should be allowed with Imaginary');
		}
	}

	/**
	 * GIF, BMP and WEBP are the formats of memes and screenshots, so they are
	 * never enabled by the backend detection, no matter what it can decode.
	 */
	public function testNonPhotographicFormatsAreNeverEnabledByTheBackend() {
		$mimetypes = ['image/gif', 'image/bmp', 'image/x-ms-bmp', 'image/webp'];

		$formats = array_keys(SettingsService::EXTENDED_MIMETYPES);
		$service = $this->makeSettingsService($this->makeConfig(), $formats);
		foreach ($mimetypes as $mimetype) {
			$this->assertFalse($service->isAllowedMimetype($mimetype), $mimetype . ' should not be allowed by backend detection');
		}

		$imaginary = new SettingsService($this->makeConfig([], 'http://localhost:9000'), null);
		foreach ($mimetypes as $mimetype) {
			$this->assertFalse($imaginary->isAllowedMimetype($mimetype), $mimetype . ' should not be allowed with Imaginary');
		}
	}

	public function testAdministratorConfigIsAlwaysHonored() {
		$service = $this->makeSettingsService($this->makeConfig(['image/gif', 'image/jxl']));

		$this->assertTrue($service->isAllowedMimetype('image/gif'));
		$this->assertTrue($service->isAllowedMimetype('image/jxl'));
		$this->assertTrue($service->isAllowedMimetype('image/jpeg'));
	}

	/**
	 * Config that keeps the app values in memory, starting from $appValues.
	 */
	private function makeAppConfig(array $appValues): IConfig {
		$values = $appValues;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
		       ->willReturnCallback(function ($app, $key, $default = '') use (&$values) {
		           return $values[$key] ?? $default;
		       });
		$config->method('setAppValue')
		       ->willReturnCallback(function ($app, $key, $value) use (&$values) {
		           $values[$key] = (string) $value;
		       });
		$config->method('deleteAppValue')
		       ->willReturnCallback(function ($app, $key) use (&$values) {
		           unset($values[$key]);
		       });
		return $config;
	}

	private function cosineProfile(string $descriptor = 'edgeface_base'): array {
		return [
			'descriptor' => $descriptor,
			'distance' => Distance::COSINE,
			'sensitivity' => ['min' => 0.3, 'default' => 0.5, 'max' => 0.7],
			'link_suggestion_sensitivity' => 0.6,
			'confidence' => ['min' => 0.5, 'default' => 0.8, 'max' => 1.0],
		];
	}

	public function testDlibProfileByDefault() {
		$service = new SettingsService($this->makeAppConfig([]), null);

		$this->assertEquals(Distance::EUCLIDEAN, $service->getDistanceMetric());
		$this->assertEquals(0.2, $service->getMinimumSensitivity());
		$this->assertEquals(0.4, $service->getSensitivity());
		$this->assertEquals(0.6, $service->getMaximumSensitivity());
		$this->assertEquals(0.55, $service->getLinkSuggestionSensitivity());
		$this->assertEquals(0.99, $service->getMinimumConfidence());
		$this->assertEquals(['min' => 0.0, 'default' => 0.99, 'max' => 1.1], $service->getMinimumConfidenceRange());
		$this->assertEquals(SettingsService::DEFAULT_DESCRIPTOR, $service->getDescriptorProfile()['descriptor']);
	}

	/**
	 * The profile reported by the external service only applies while it is
	 * the current model.
	 */
	public function testExternalProfileOnlyAppliesToTheExternalModel() {
		$config = $this->makeAppConfig([SettingsService::CURRENT_MODEL_KEY => '1']);
		$service = new SettingsService($config, null);
		$service->setExternalDescriptorProfile($this->cosineProfile());

		$this->assertEquals(Distance::EUCLIDEAN, $service->getDistanceMetric());

		$service->setCurrentFaceModel(ExternalModel::FACE_MODEL_ID);
		$this->assertEquals(Distance::COSINE, $service->getDistanceMetric());
		$this->assertEquals(0.3, $service->getMinimumSensitivity());
		$this->assertEquals(0.5, $service->getSensitivity());
		$this->assertEquals(0.7, $service->getMaximumSensitivity());
		$this->assertEquals(0.6, $service->getLinkSuggestionSensitivity());
		$this->assertEquals(0.8, $service->getMinimumConfidence());
	}

	/**
	 * A threshold saved for other descriptors is forgotten when the profile
	 * changes, and one out of the range falls back to the default anyway.
	 */
	public function testThresholdsAreResetWhenTheProfileChanges() {
		$config = $this->makeAppConfig([
			SettingsService::CURRENT_MODEL_KEY => strval(ExternalModel::FACE_MODEL_ID),
			SettingsService::SENSITIVITY_KEY => '0.35',
			SettingsService::LINK_SUGGESTION_SENSITIVITY_KEY => '0.5',
			SettingsService::MINIMUM_CONFIDENCE_KEY => '0.95',
		]);
		$service = new SettingsService($config, null);
		$this->assertEquals(0.35, $service->getSensitivity());

		$service->setExternalDescriptorProfile($this->cosineProfile());
		$this->assertEquals(0.5, $service->getSensitivity());
		$this->assertEquals(0.6, $service->getLinkSuggestionSensitivity());
		$this->assertEquals(0.8, $service->getMinimumConfidence());

		// The same profile again keeps what the administrator chose.
		$service->setSensitivity('0.45');
		$service->setExternalDescriptorProfile($this->cosineProfile());
		$this->assertEquals(0.45, $service->getSensitivity());

		// Out of the range of the profile.
		$service->setSensitivity('0.9');
		$this->assertEquals(0.5, $service->getSensitivity());
		$service->setMinimumConfidence('0.99');
		$this->assertEquals(0.99, $service->getMinimumConfidence());
		$service->setMinimumConfidence('1.05');
		$this->assertEquals(0.8, $service->getMinimumConfidence());
	}

	/**
	 * Another detector with the same descriptors changes the scale of the
	 * confidence, but not the meaning of the clustering threshold.
	 */
	public function testOnlyTheConfidenceIsResetWhenTheDetectorChanges() {
		$config = $this->makeAppConfig([SettingsService::CURRENT_MODEL_KEY => strval(ExternalModel::FACE_MODEL_ID)]);
		$service = new SettingsService($config, null);
		$service->setExternalDescriptorProfile($this->cosineProfile());
		$service->setSensitivity('0.45');
		$service->setMinimumConfidence('0.9');

		$profile = $this->cosineProfile();
		$profile['confidence'] = ['min' => 0.4, 'default' => 0.7, 'max' => 1.0];
		$service->setExternalDescriptorProfile($profile);

		$this->assertEquals(0.45, $service->getSensitivity());
		$this->assertEquals(0.7, $service->getMinimumConfidence());
	}

	public function testOlderServicesAreDlib() {
		$profile = SettingsService::normalizeDescriptorProfile([]);
		$this->assertEquals(SettingsService::DEFAULT_DESCRIPTOR, $profile['descriptor']);
		$this->assertEquals(Distance::EUCLIDEAN, $profile['distance']);
		$this->assertEquals(['min' => 0.2, 'default' => 0.4, 'max' => 0.6], $profile['sensitivity']);
		$this->assertEquals(['min' => 0.0, 'default' => 0.99, 'max' => 1.1], $profile['confidence']);
	}

	public function testUnknownMetricIsRefused() {
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeDescriptorProfile(['distance' => 'manhattan']);
	}

	public function testInvalidConfidenceRangeIsRefused() {
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeDescriptorProfile(['confidence' => ['min' => 0.5, 'default' => 0.4, 'max' => 1.0]]);
	}

	public function testInvalidRangeIsRefused() {
		$this->expectException(\InvalidArgumentException::class);
		SettingsService::normalizeDescriptorProfile(['sensitivity' => ['min' => 0.5, 'default' => 0.4, 'max' => 0.6]]);
	}
}

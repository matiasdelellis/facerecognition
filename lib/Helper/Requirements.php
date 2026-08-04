<?php
namespace OCA\FaceRecognition\Helper;

use OCP\App\IAppManager;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Service\SettingsService;

class Requirements
{
	public static function hasEnoughMemory(): bool {
		$memory = MemoryLimits::getSystemMemory();
		return ($memory > SettingsService::MINIMUM_SYSTEM_MEMORY_REQUIREMENTS);
	}

	public static function pdlibLoaded(): bool {
		return extension_loaded('pdlib');
	}

	public static function memoriesIsInstalled(): bool {
		$appManager = \OC::$server->get(IAppManager::class);
		return $appManager->isEnabledForUser('memories');
	}

	/**
	 * Formats that the Imagick extension can decode, as an array of format
	 * names (for example "HEIC" or "TIFF"). Empty when Imagick is not loaded.
	 *
	 * @return string[]
	 */
	public static function imagickSupportedFormats(): array {
		if (!extension_loaded('imagick')) {
			return [];
		}

		try {
			return \Imagick::queryFormats();
		} catch (\Exception $e) {
			return [];
		}
	}

}

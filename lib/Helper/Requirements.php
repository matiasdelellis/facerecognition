<?php
namespace OCA\FaceRecognition\Helper;

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

}

<?php
namespace OCA\FaceRecognition\Helper;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Service\SettingsService;

class Requirements
{
	public static function hasEnoughMemory() {
		$memory = MemoryLimits::getSystemMemory();
		return ($memory > SettingsService::MINIMUM_SYSTEM_MEMORY_REQUIREMENTS);
	}

	public static function pdlibLoaded() {
		return extension_loaded('pdlib');
	}

}

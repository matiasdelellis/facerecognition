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

}

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
namespace OCA\FaceRecognition\Tests\Unit\AppInfo;

use OCA\FaceRecognition\AppInfo\Capabilities;
use OCA\FaceRecognition\Service\SettingsService;

use OCP\App\IAppManager;

use Test\TestCase;

class CapabilitiesTest extends TestCase {

	private function makeCapabilities(bool $enabled, ?string $userId = 'user'): Capabilities {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.9.95');

		$settingsService = $this->createMock(SettingsService::class);
		if (!is_null($userId)) {
			$settingsService->expects($this->once())
			                ->method('getUserEnabled')
			                ->with($userId)
			                ->willReturn($enabled);
		} else {
			$settingsService->expects($this->never())
			                ->method('getUserEnabled');
		}

		return new Capabilities($appManager, $settingsService, $userId);
	}

	public function testEnabledUser() {
		$capabilities = $this->makeCapabilities(true, 'user');
		$result = $capabilities->getCapabilities();

		$this->assertTrue($result['facerecognition']['enabled']);
	}

	/**
	 * The settings are stored as the strings "true" and "false"; the old
	 * boolval() reported true for both.
	 */
	public function testDisabledUser() {
		$capabilities = $this->makeCapabilities(false, 'user');
		$result = $capabilities->getCapabilities();

		$this->assertFalse($result['facerecognition']['enabled']);
	}

	public function testAnonymousUserIsDisabled() {
		$capabilities = $this->makeCapabilities(false, null);
		$result = $capabilities->getCapabilities();

		$this->assertFalse($result['facerecognition']['enabled']);
	}
}

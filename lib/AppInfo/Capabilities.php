<?php declare(strict_types=1);
/*
 * @copyright 2022 Matias De lellis <mati86dl@gmail.com>
 *
 * @author 2022 Matias De lellis <mati86dl@gmail.com>
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
 */


namespace OCA\FaceRecognition\AppInfo;

use OCP\App\IAppManager;
use OCP\IConfig;

use OCP\Capabilities\ICapability;

class Capabilities implements ICapability {

	/** @var IAppManager */
	private $appManager;

	/** @var IConfig */
	protected $config;

	/** @var string */
	private $userId;

	public function __construct(IAppManager $appManager,
	                            IConfig     $config,
	                            $userId) {
		$this->appManager = $appManager;
		$this->config     = $config;
		$this->userId     = $userId;
	}

	public function getCapabilities() {
		return [
			Application::APP_NAME => [
				'version'     => $this->appManager->getAppVersion(Application::APP_NAME),
				'apiVersions' => Application::API_VERSIONS,
				'enabled'     => boolval($this->config->getUserValue($this->userId, Application::APP_NAME, 'enabled', false)),
			],
		];
	}

}
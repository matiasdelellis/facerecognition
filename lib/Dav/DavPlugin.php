<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Dav;

use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

use OCA\FaceRecognition\AppInfo\Application;
use OCA\FaceRecognition\Service\DavService;

class DavPlugin extends ServerPlugin {

	/** @var Server */
	protected $server;

	/**
	 * Initializes the plugin and registers event handlers
	 *
	 * @param Server $server
	 * @return void
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$server->on('propFind', [$this, 'getPersons']);
	}


	/**
	 * @param PropFind $propFind
	 * @param INode $node
	 */
	public function getPersons(PropFind $propFind, INode $node) {
		// we instantiate the DavService here to make sure sabre auth backend was triggered
		$davService = \OC::$server->get(DavService::class);
		$davService->propFind($propFind, $node);
	}

	/**
	 * Returns a plugin name.
	 *
	 * Using this name other plugins will be able to access other plugins
	 * using \Sabre\DAV\Server::getPlugin
	 *
	 * @return string
	 */
	public function getPluginName(): string {
		return Application::APP_NAME;
	}

	/**
	 * Returns a bunch of meta-data about the plugin.
	 *
	 * Providing this information is optional, and is mainly displayed by the
	 * Browser plugin.
	 *
	 * The description key in the returned array may contain html and will not
	 * be sanitized.
	 *
	 * @return array
	 */
	public function getPluginInfo(): array {
		return [
			'name'        => $this->getPluginName(),
			'description' => 'Provides information on Face Recognition in PROPFIND WebDav requests',
		];
	}
}

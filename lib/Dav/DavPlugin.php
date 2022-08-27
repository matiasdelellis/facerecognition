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

use OCA\DAV\Connector\Sabre\Directory;

use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Xml\Element\Response;
use Sabre\DAV\Xml\Response\MultiStatus;

use Sabre\DAV\Exception\BadRequest;

use OCA\FaceRecognition\AppInfo\Application;
use OCA\FaceRecognition\Service\DavService;

class DavPlugin extends ServerPlugin {

	/**
	 * Reference to main server object
	 *
	 * @var \Sabre\DAV\Server
	 */
	private $server;

	/**
	 * Initializes the plugin and registers event handlers
	 *
	 * @param Server $server
	 * @return void
	 */
	public function initialize(Server $server) {
		$server->xml->namespaceMap[Application::DAV_NS_FACE_RECOGNITION] = 'fr';

		$this->server = $server;
		$this->server->on('propFind', [$this, 'onPropFind']);
		$this->server->on('report', [$this, 'onReport']);
	}

	/**
	 * @param PropFind $propFind
	 * @param INode $node
	 */
	public function onPropFind(PropFind $propFind, INode $node) {
		// we instantiate the DavService here to make sure sabre auth backend was triggered
		$davService = \OC::$server->get(DavService::class);
		$davService->propFind($propFind, $node);
	}

	/**
	 * REPORT operations to look for files
	 *
	 * @param string $reportName
	 * @param $report
	 * @param string $uri
	 * @return bool
	 * @throws BadRequest
	 * @internal param $ [] $report
	 */
	public function onReport($reportName, $report, $uri) {
		if ($reportName !== Application::DAV_REPORT_FILES) {
			return;
		}

		$reportTargetNode = $this->server->tree->getNodeForPath($uri);
		if (!$reportTargetNode instanceof Directory) {
			return;
		}

		$requestedProps = [];
		$filterRules = [];

		// parse report properties and gather filter info
		foreach ($report as $reportProps) {
			$name = $reportProps['name'];
			if ($name === Application::DAV_REPORT_FILTER_RULES) {
				$filterRules = $reportProps['value'];
			} elseif ($name === '{DAV:}prop') {
				// propfind properties
				foreach ($reportProps['value'] as $propVal) {
					$requestedProps[] = $propVal['name'];
				}
			}
		}

		if (empty($filterRules)) {
			throw new BadRequest('Missing filter-name block in request');
		}

		// gather all sabre nodes matching filter
		$results = $this->processFilterRules($filterRules);

		$filesUri = $this->getFilesBaseUri($uri, $reportTargetNode->getPath());
		$responses = $this->prepareResponses($filesUri, $requestedProps, $results);

		$xml = $this->server->xml->write(
			'{DAV:}multistatus',
			new MultiStatus($responses)
		);

		$this->server->httpResponse->setStatus(207);
		$this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
		$this->server->httpResponse->setBody($xml);

		return false;
	}

	/**
	 * Find sabre nodes matching the given filter rules
	 *
	 * @param array $filterRules
	 * @return array array of unique sabre nodes.
	 */
	protected function processFilterRules($filterRules): array {
		$namedFilter = [];
		foreach ($filterRules as $filterRule) {
			if ($filterRule['name'] === Application::DAV_REPORT_FILTER_NAME) {
				$namedFilter[] = $filterRule['value'];
			}
		}

		// we instantiate the DavService here to make sure sabre auth backend was triggered
		$davService = \OC::$server->get(DavService::class);
		return $davService->getFilesNamedFilter($namedFilter);
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
	 * Returns a list of reports this plugin supports.
	 *
	 * This will be used in the {DAV:}supported-report-set property.
	 *
	 * @param string $uri
	 * @return array
	 */
	public function getSupportedReportSet($uri) {
		return [Application::DAV_REPORT_FILES];
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

	/**
	 * Returns the base uri of the files root by removing
	 * the subpath from the URI
	 *
	 * @param string $uri URI from this request
	 * @param string $subPath subpath to remove from the URI
	 *
	 * @return string files base uri
	 */
	private function getFilesBaseUri(string $uri, string $subPath): string {
		$uri = trim($uri, '/');
		$subPath = trim($subPath, '/');
		if (empty($subPath)) {
			$filesUri = $uri;
		} else {
			$filesUri = substr($uri, 0, strlen($uri) - strlen($subPath));
		}
		$filesUri = trim($filesUri, '/');
		if (empty($filesUri)) {
			return '';
		}
		return '/' . $filesUri;
	}

	/**
	 * Prepare propfind response for the given nodes
	 *
	 * @param string $filesUri $filesUri URI leading to root of the files URI,
	 * with a leading slash but no trailing slash
	 * @param string[] $requestedProps requested properties
	 * @param Node[] nodes nodes for which to fetch and prepare responses
	 * @return Response[]
	 */
	private function prepareResponses($filesUri, $requestedProps, $nodes): array {
		$responses = [];
		foreach ($nodes as $node) {
			$propFind = new PropFind($filesUri . $node->getPath(), $requestedProps);

			$this->server->getPropertiesByNode($propFind, $node);
			// copied from Sabre Server's getPropertiesForPath
			$result = $propFind->getResultForMultiStatus();
			$result['href'] = $propFind->getPath();

			$resourceType = $this->server->getResourceTypeForNode($node);
			if (in_array('{DAV:}collection', $resourceType) || in_array('{DAV:}principal', $resourceType)) {
				$result['href'] .= '/';
			}

			$responses[] = new Response(
				rtrim($this->server->getBaseUri(), '/') . $filesUri . $node->getPath(),
				$result,
				200
			);
		}
		return $responses;
	}

}

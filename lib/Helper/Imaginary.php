<?php
/**
 * @copyright Copyright (c) 2022, Matias De lellis
 *
 * @author Matias De lellis <mati86dl@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\FaceRecognition\Helper;

use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IImage;

use OC\StreamImage;
use Psr\Log\LoggerInterface;

class Imaginary {

	/** @var IConfig */
	private $config;

	/** @var IClientService */
	private $service;

	/** @var LoggerInterface */
	private $logger;

	public function __construct() {
		$this->config = \OC::$server->get(IConfig::class);
		$this->service = \OC::$server->get(IClientService::class);
		$this->logger = \OC::$server->get(LoggerInterface::class);
	}

	public function isEnabled(): bool {
		$imaginaryUrl = $this->config->getSystemValueString('preview_imaginary_url', 'invalid');
		return ($imaginaryUrl !== 'invalid');
	}

	public function getInfo(string $filepath): array {

		$imaginaryUrl = $this->config->getSystemValueString('preview_imaginary_url', 'invalid');
		$imaginaryUrl = rtrim($imaginaryUrl, '/');

		$httpClient = $this->service->newClient();

		$options['multipart'] = [[
			'name' => 'file',
			'contents' => file_get_contents($filepath),
			'filename' => basename($filepath),
		]];

		try {
			$response = $httpClient->post($imaginaryUrl . '/info', $options);
		} catch (\Exception $e) {
			$this->logger->error('Error getting image information in Imaginary: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return [];
		}

		if ($response->getStatusCode() !== 200) {
			$this->logger->error('Error getting image information in Imaginary: ' . json_decode($response->getBody())['message']);
			return [];
		}

		return json_decode($response->getBody(), true);
	}

	/**
	 * @return false|resource|\GdImage Returns the resized image
	 */
	public function getResized(string $filepath, int $width, int $height, string $mimeType) {

		$imaginaryUrl = $this->config->getSystemValueString('preview_imaginary_url', 'invalid');
		$imaginaryUrl = rtrim($imaginaryUrl, '/');

		// Object store
		$stream = fopen($filepath, 'r');

		$httpClient = $this->service->newClient();

		switch ($mimeType) {
			case 'image/png':
				$type = 'png';
				break;
			default:
				$type = 'jpeg';
		}

		$operations = [
			[
				'operation' => 'autorotate',
			],
			[
				'operation' => 'resize',
				'params' => [
					'width' => $width,
					'height' => $height,
					'stripmeta' => 'true',
					'type' => $type,
					'norotation' => 'true',
				]
			]
		];

		try {
			$response = $httpClient->post(
				$imaginaryUrl . '/pipeline', [
					'query' => ['operations' => json_encode($operations)],
					'body' => file_get_contents($filepath),
					'nextcloud' => ['allow_local_address' => true],
				]);
		} catch (\Exception $e) {
			$this->logger->error('Error generating temporary image in Imaginary: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return false;
		}

		if ($response->getStatusCode() !== 200) {
			$this->logger->error('Error generating temporary image in Imaginary: ' . json_decode($response->getBody())['message']);
			return false;
		}

		$body = $response->getBody();

		return $body;
	}

}

<?php
/**
 * @copyright Copyright (c) 2022-2023, Matias De lellis
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

class Imaginary {

	/** System settings where the server configures the Imaginary service */
	const SYSTEM_URL = 'preview_imaginary_url';
	const SYSTEM_KEY = 'preview_imaginary_key';

	/** @var IConfig */
	private $config;

	/** @var IClientService */
	private $service;

	public function __construct() {
		$this->config = \OC::$server->get(IConfig::class);
		$this->service = \OC::$server->get(IClientService::class);
	}

	public function isEnabled(): bool {
		return !is_null($this->getUrl());
	}

	public function getUrl(): ?string {
		$imaginaryUrl = trim($this->config->getSystemValueString(self::SYSTEM_URL, 'invalid'));
		if ($imaginaryUrl === '' || $imaginaryUrl === 'invalid')
			return null;

		return rtrim($imaginaryUrl, '/');
	}

	public function hasKey(): bool {
		return !is_null($this->getKey());
	}

	public function getKey(): ?string {
		$imaginaryKey = trim($this->config->getSystemValueString(self::SYSTEM_KEY, 'invalid'));
		if ($imaginaryKey === '' || $imaginaryKey === 'invalid')
			return null;

		return $imaginaryKey;
	}

	/**
	 * Message of an error answer of the service.
	 *
	 * The body is decoded as an array on purpose: decoding it as an object and
	 * then asking for ['message'] throws an Error, which the callers of this
	 * helper do not catch.
	 */
	private function errorMessage($response): string {
		$body = (string)$response->getBody();
		$decoded = json_decode($body, true);
		if (is_array($decoded) && isset($decoded['message'])) {
			return (string)$decoded['message'];
		}

		return $body;
	}

	/**
	 * @return string imaginary version
	 */
	public function getVersion(): ?string {
		$imaginaryUrl = $this->getUrl();
		if (!$imaginaryUrl) {
			throw new \RuntimeException('Try to use imaginary without valid url');
		}

		$httpClient = $this->service->newClient();

		try {
			$options = [
				'timeout' => 60,
				// Imaginary is normally a local service, and Nextcloud blocks
				// local addresses unless the client is explicitly told not to.
				'nextcloud' => ['allow_local_address' => true],
			];
			if ($this->hasKey()) {
				$options['query'] = [
					'key' => $this->getKey(),
				];
			}
			$response = $httpClient->get($imaginaryUrl . '/', $options);
		} catch (\Exception $e) {
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$info = json_decode($response->getBody(), true);

		return is_array($info) ? ($info['imaginary'] ?? null) : null;
	}

	/**
	 * @return array Returns the array with the size of image.
	 */
	public function getInfo(string $filepath): array {
		$imaginaryUrl = $this->getUrl();
		if (!$imaginaryUrl) {
			throw new \RuntimeException('Try to use imaginary without valid url');
		}

		$httpClient = $this->service->newClient();

		$contents = @file_get_contents($filepath);
		if ($contents === false) {
			throw new \RuntimeException('Could not read the image to send to Imaginary: ' . $filepath);
		}

		$options = [
			'timeout' => 60,
			'nextcloud' => ['allow_local_address' => true],
		];
		$options['multipart'] = [[
			'name' => 'file',
			'contents' => $contents,
			'filename' => basename($filepath),
		]];

		if ($this->hasKey()) {
			$options['query'] = [
				'key' => $this->getKey(),
			];
		}

		$response = $httpClient->post($imaginaryUrl . '/info', $options);

		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('Error getting image information in Imaginary: ' . $this->errorMessage($response));
		}

		$info = json_decode($response->getBody(), true);
		if (!is_array($info) || !isset($info['width'], $info['height'])) {
			throw new \RuntimeException('Imaginary answered the image information with an invalid body.');
		}

		$type = (string)($info['type'] ?? '');
		$orientation = (int)($info['orientation'] ?? 1);
		$width = (int)$info['width'];
		$height = (int)$info['height'];
		//NOTE: Imaginary has problems rotating HEIC/HEIF images. Issue #662
		$autorotate = ($orientation > 4 && $type !== 'heif' && $type !== 'heic');

		return [
			'type'   => $type,
			'autorotate' => $autorotate,
			// Rotates the size, since it is important and Imaginary do not do that.
			'width'  => $autorotate ? $height : $width,
			'height' => $autorotate ? $width : $height
		];
	}

	/**
	 * @return string|resource Returns the resized image
	 */
	public function getResized(string $filepath, int $width, int $height, bool $autorotate, string $mimeType) {

		$imaginaryUrl = $this->getUrl();
		if (!$imaginaryUrl) {
			throw new \RuntimeException('Try to use imaginary without valid url');
		}

		$httpClient = $this->service->newClient();

		switch ($mimeType) {
			case 'image/png':
				$type = 'png';
				break;
			default:
				$type = 'jpeg';
		}

		$operations = [];

		if ($autorotate) {
			$operations[] = [
				'operation' => 'autorotate',
			];
		}

		$operations[] = [
			'operation' => 'resize',
			'params' => [
				'width' => $width,
				'height' => $height,
				'stripmeta' => 'true',
				'type' => $type,
				'norotation' => 'true',
				'force' => 'true'
			]
		];

		$query = [];
		$query['operations'] = json_encode($operations);
		if ($this->hasKey()) {
			$query['key'] = $this->getKey();
		}

		$contents = @file_get_contents($filepath);
		if ($contents === false) {
			throw new \RuntimeException('Could not read the image to send to Imaginary: ' . $filepath);
		}

		$response = $httpClient->post(
			$imaginaryUrl . '/pipeline', [
				'query' => $query,
				'body' => $contents,
				'timeout' => 60,
				'nextcloud' => ['allow_local_address' => true],
			]);

		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('Error generating temporary image in Imaginary: ' . $this->errorMessage($response));
		}

		$body = $response->getBody();
		if ($body === null || $body === '') {
			throw new \RuntimeException('Imaginary answered the resize with an empty body.');
		}

		return $body;
	}

}

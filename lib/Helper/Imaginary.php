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

	/** @var IConfig */
	private $config;

	/** @var IClientService */
	private $service;

	public function __construct() {
		$this->config = \OC::$server->get(IConfig::class);
		$this->service = \OC::$server->get(IClientService::class);
	}

	public function isEnabled(): bool {
		$imaginaryUrl = $this->config->getSystemValueString('preview_imaginary_url', 'invalid');
		return ($imaginaryUrl !== 'invalid');
	}

	public function getUrl(): ?string {
		$imaginaryUrl = $this->config->getSystemValueString('preview_imaginary_url', 'invalid');
		if ($imaginaryUrl === 'invalid')
			return null;

		return rtrim($imaginaryUrl, '/');
	}

	public function hasKey(): bool {
		$imaginaryKey = $this->config->getSystemValueString('preview_imaginary_key', 'invalid');
		return ($imaginaryKey !== 'invalid');
	}

	public function getKey(): ?string {
		$imaginaryKey = $this->config->getSystemValueString('preview_imaginary_key', 'invalid');
		if ($imaginaryKey === 'invalid')
			return null;
               
		return $imaginaryKey;
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
			$options = [];
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

		return $info['imaginary'];
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

		$options = [];
		$options['multipart'] = [[
			'name' => 'file',
			'contents' => file_get_contents($filepath),
			'filename' => basename($filepath),
		]];

		if ($this->hasKey()) {
			$options['query'] = [
				'key' => $this->getKey(),
			];
		}

		$response = $httpClient->post($imaginaryUrl . '/info', $options);

		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('Error getting image information in Imaginary: ' . json_decode($response->getBody())['message']);
		}

		$info = json_decode($response->getBody(), true);

		$type = $info['type'];
		//NOTE: Imaginary has problems rorating heic images. Issue #662
		$autorotate = ($info['orientation'] > 4 && $type != 'heif');

		return [
			'type'   => $type,
			'autorotate' => $autorotate,
			// Rotates the size, since it is important and Imaginary do not do that.
			'width'  => $autorotate ? $info['height'] : $info['width'],
			'height' => $autorotate ? $info['width'] :  $info['height']
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

		$response = $httpClient->post(
			$imaginaryUrl . '/pipeline', [
				'query' => $query,
				'body' => file_get_contents($filepath),
				'nextcloud' => ['allow_local_address' => true],
			]);

		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('Error generating temporary image in Imaginary: ' . json_decode($response->getBody())['message']);
		}

		return $response->getBody();
	}

}

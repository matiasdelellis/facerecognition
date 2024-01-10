<?php
/**
 * @copyright Copyright (c) 2021, Matias De lellis <mati86dl@gmail.com>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Model\ExternalModel;

use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\IModel;

use OCA\FaceRecognition\Model\Exceptions\UnavailableException;


class ExternalModel implements IModel {
	/*
	 * Model description
	 */
	const FACE_MODEL_ID = 5;
	const FACE_MODEL_NAME = 'ExternalModel';
	const FACE_MODEL_DESC = 'External model to separate image processing from the web server';
	const FACE_MODEL_DOC = 'https://github.com/matiasdelellis/facerecognition-external-model#run-service';

	/** This model practically does not consume memory. Directly set the limits. */
	const MINIMUM_MEMORY_REQUIREMENTS = 128 * 1024 * 1024;

	/** @var String|null model api endpoint */
	private $modelUrl = null;

	/** @var String|null model api key */
	private $modelApiKey = null;

	/** @var String|null preferred mimetype */
	private $preferredMimetype = null;

	/** @var int maximun image area */
	private $maximumImageArea = -1;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * ExternalModel __construct.
	 *
	 * @param SettingsService $settingsService
	 */
	public function __construct(SettingsService $settingsService)
	{
		$this->settingsService = $settingsService;
	}

	public function getId(): int {
		return static::FACE_MODEL_ID;
	}

	public function getName(): string {
		return static::FACE_MODEL_NAME;
	}

	public function getDescription(): string {
		return static::FACE_MODEL_DESC;
	}

	public function getDocumentation(): string {
		return static::FACE_MODEL_DOC;
	}

	public function isInstalled(): bool {
		$this->modelUrl = $this->settingsService->getExternalModelUrl();
		$this->modelApiKey = $this->settingsService->getExternalModelApiKey();
		return !is_null($this->modelUrl) && !is_null($this->modelApiKey);
	}

	public function meetDependencies(string &$error_message): bool {
		if (is_null($this->settingsService->getExternalModelUrl())) {
			$error_message = "You still need to configure the URL of the service running the model.";
			return false;
		}
		if (is_null($this->settingsService->getExternalModelApiKey())) {
			$error_message = "You still need to configure the API KEY of the service running the model.";
			return false;
		}
		return true;
	}

	public function getMaximumArea(): int {
		if ($this->maximumImageArea < 0) {
			throw new \Exception('It seems that the model did not open correctly');
		}
		return $this->maximumImageArea;
	}

	public function getPreferredMimeType(): string {
		if (is_null($this->preferredMimetype)) {
			throw new \Exception('It seems that the model did not open correctly');
		}
		return $this->preferredMimetype;
	}

	/**
	 * @return void
	 */
	public function install() {
		$this->open();
		return;
	}

	/**
	 * @return void
	 */
	public function open() {
		$this->modelUrl = $this->settingsService->getExternalModelUrl();
		$this->modelApiKey = $this->settingsService->getExternalModelApiKey();

		$ch = curl_init();
		if ($ch === false) {
			throw new \Exception('Curl error: unable to initialize curl');
		}

		curl_setopt($ch, CURLOPT_URL, $this->modelUrl . '/open');
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $this->modelApiKey]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($ch);
		if (is_bool($response)) {
			throw new UnavailableException(curl_error($ch));
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode !== 200) {
			throw new \Exception('External model response /open with error. HTTP status code: ' . $httpCode);
		}

		$jsonResponse = json_decode($response, true);

		$this->maximumImageArea = intval($jsonResponse['maximum_area']);
		$this->preferredMimetype = $jsonResponse['preferred_mimetype'];

		curl_close($ch);
	}

	public function detectFaces(string $imagePath, bool $compute = true): array {
		$ch = curl_init();
		if ($ch === false) {
			throw new \Exception('Curl error: unable to initialize curl');
		}

		$cFile = curl_file_create($imagePath);
		$post = array('file'=> $cFile);

		curl_setopt($ch, CURLOPT_URL, $this->modelUrl . '/detect');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $this->modelApiKey]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($ch);
		if (is_bool($response)) {
			throw new UnavailableException(curl_error($ch));
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode !== 200) {
			throw new \Exception('External model response /detect with error. HTTP status code: ' . $httpCode);
		}

		curl_close($ch);

		$jsonResponse = json_decode($response, true);

		if (!is_array($jsonResponse))
			return [];

		if ($jsonResponse['faces-count'] == 0)
			return [];

		return $jsonResponse['faces'];
	}

	public function compute(string $imagePath, array $face): array {
		$ch = curl_init();
		if ($ch === false) {
			throw new \Exception('Curl error: unable to initialize curl');
		}

		$cFile = curl_file_create($imagePath, $this->preferredMimetype, basename($imagePath));
		$post = [
			'file' => $cFile,
			'face' => json_encode($face),
		];

		curl_setopt($ch, CURLOPT_URL, $this->modelUrl . '/compute');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key:' . $this->modelApiKey]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($ch);
		if (is_bool($response)) {
			throw new UnavailableException(curl_error($ch));
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpCode !== 200) {
			throw new \Exception('External model response /compute with error. HTTP status code: ' . $httpCode);
		}

		curl_close($ch);

		$jsonResponse = json_decode($response, true);

		return $jsonResponse['face'];
	}

}

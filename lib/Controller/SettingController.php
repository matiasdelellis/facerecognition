<?php

namespace OCA\FaceRecognition\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingController extends Controller {

	private $config;
	private $userId;

	public function __construct ($appName,
	                             IRequest $request,
	                             IConfig $config,
	                             $userId)
	{
		parent::__construct($appName, $request);
		$this->appName = $appName;
		$this->userId = $userId;
		$this->config = $config;
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 * @throws \OCP\PreConditionNotMetException
	 */
	public function setValue($type, $value) {
		$success = false;
		switch ($type) {
			case 'enabled':
				$success = $this->setEnableUser($this->userId, $value);
				break;
			default:
				break;
		}
		$result = [
			'success' => $success ? 'true' : 'false'
		];
		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @return JSONResponse
	 */
	public function getValue($type) {
		$value = $this->config->getUserValue($this->userId, $this->appName, $type);
		if ($value !== '') {
			$result = [
				'status' => 'success',
				'value' => $value
			];
		} else {
			$result = [
				'status' => 'false',
				'value' =>'nodata'
			];
		}
		$response = new JSONResponse();
		$response->setData($result);
		return $response;
	}

	protected function setEnableUser (string $userId, $enabled): bool {
		$success = false;
		$this->config->setUserValue($this->userId, $this->appName, 'enabled', $enabled);
		if ($enabled == 'false') {
			// TODO: Invalidate images and remove user info here???
			$success = true;
		}
		else {
			$success = true;
		}
		return $success;
	}

}

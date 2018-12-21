<?php

namespace OCA\FaceRecognition\Controller;

use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingController extends Controller {

	/** @var IConfig */
	private $config;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var string */
	private $userId;

	public function __construct ($appName,
	                             IRequest     $request,
	                             IConfig      $config,
	                             FaceMapper   $faceMapper,
	                             ImageMapper  $imageMapper,
	                             PersonMapper $personMapper
	                             $userId)
	{
		parent::__construct($appName, $request);
		$this->appName = $appName;
		$this->config = $config;
		$this->faceMapper = $faceMapper;
		$this->imageMapper = $imageMapper;
		$this->personMapper = $personMapper;
		$this->userId = $userId;
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
		$this->config->setUserValue($userId, $this->appName, 'enabled', $enabled);
		if ($enabled == 'false') {
			// TODO: Invalidate images and remove user info here???
			//$this->faceMapper->resetUser($this->userId);
			//$this->personMapper->resetUser($this->userId);
			//$this->imageMapper->resetUser($this->userId);
			$success = true;
		}
		else {
			$success = true;
		}
		return $success;
	}

}

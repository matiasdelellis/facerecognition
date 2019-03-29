<?php

namespace OCA\FaceRecognition\Controller;

use OCA\FaceRecognition\FaceManagementService;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;

class SettingController extends Controller {

	/** @var IConfig */
	private $config;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var string */
	private $userId;

	/** @var FaceManagementService */
	private $faceManagementService;

	const STATE_OK = 0;
	const STATE_FALSE = 1;
	const STATE_SUCCESS = 2;
	const STATE_ERROR = 3;

	public function __construct ($appName,
	                             IRequest              $request,
	                             IConfig               $config,
	                             IUserManager          $userManager,
	                             FaceManagementService $faceManagementService,
	                             $userId)
	{
		parent::__construct($appName, $request);
		$this->appName               = $appName;
		$this->config                = $config;
		$this->userManager           = $userManager;
		$this->faceManagementService = $faceManagementService;
		$this->userId                = $userId;
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 */
	public function setUserValue($type, $value) {
		// Apply the change of settings
		$this->config->setUserValue($this->userId, $this->appName, $type, $value);

		// Handles special cases when have to do something else according to the change
		switch ($type) {
			case 'enabled':
				if ($value === 'false')
					$this->faceManagementService->resetAllForUser($this->userId);
				break;
			default:
				break;
		}

		// Response
		$result = [
			'status' => self::STATE_SUCCESS,
			'value' => $value
		];
		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @param $type
	 * @return JSONResponse
	 */
	public function getUserValue($type) {
		$value = $this->config->getUserValue($this->userId, $this->appName, $type);
		if ($value !== '') {
			$result = [
				'status' => self::STATE_OK,
				'value' => $value
			];
		} else {
			$result = [
				'status' => self::STATE_FALSE,
				'value' =>'nodata'
			];
		}
		return new JSONResponse($result);
	}

	/**
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 */
	public function setAppValue($type, $value) {
		// Apply the change of settings
		$this->config->setAppValue('facerecognition', $type, $value);

		// Handles special cases when have to do something else according to the change
		switch ($type) {
			case 'sensitivity':
				$this->userManager->callForSeenUsers(function(IUser $user) {
					$this->config->setUserValue($user->getUID(), 'facerecognition', 'recreate-clusters', 'true');
				});
				break;
			default:
				break;
		}

		// Response
		$result = [
			'status' => self::STATE_SUCCESS,
			'value' => $value
		];
		return new JSONResponse($result);
	}

	/**
	 * @param $type
	 * @return JSONResponse
	 */
	public function getAppValue($type) {
		$value = $this->config->getAppValue('facerecognition', $type);
		if ($value !== '') {
			$result = [
				'status' => self::STATE_OK,
				'value' => $value
			];
		} else {
			$result = [
				'status' => self::STATE_FALSE,
				'value' =>'nodata'
			];
		}
		return new JSONResponse($result);
	}

}

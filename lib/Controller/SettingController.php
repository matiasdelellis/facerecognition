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
	                             PersonMapper $personMapper,
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
	 * @param $type
	 * @param $value
	 * @return JSONResponse
	 * @throws \OCP\PreConditionNotMetException
	 */
	public function setAppValue($type, $value) {
		$this->config->setAppValue('facerecognition', $type, $value);
		$result = [
			'success' => 'true',
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

}

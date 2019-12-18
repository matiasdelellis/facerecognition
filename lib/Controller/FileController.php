<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\IConfig;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\FileService;

use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

class FileController extends Controller {

	private $config;

	private $imageMapper;

	private $personMapper;

	private $faceMapper;

	private $fileService;

	private $userId;

	public function __construct($AppName,
	                            IRequest     $request,
	                            IConfig      $config,
	                            ImageMapper  $imageMapper,
	                            PersonMapper $personMapper,
	                            FaceMapper   $faceMapper,
	                            FileService  $fileService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->config       = $config;
		$this->imageMapper  = $imageMapper;
		$this->personMapper = $personMapper;
		$this->faceMapper   = $faceMapper;
		$this->fileService  = $fileService;
		$this->userId       = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getPersonsFromPath(string $fullpath) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));
		$userEnabled = $this->config->getUserValue($this->userId, 'facerecognition', 'enabled', 'false');

		$resp = array();
		if ($userEnabled !== 'true') {
			$resp['enabled'] = 'false';
			return new DataResponse($resp);
		}

		$file = $this->fileService->getFileByPath($fullpath);

		$fileId = $file->getId();
		$image = $this->imageMapper->findFromFile($this->userId, $fileId);

		$resp['enabled'] = 'true';
		$resp['is_allowed'] = $this->fileService->isAllowedNode($file);
		$resp['parent_detection'] = !$this->fileService->isUnderNoDetection($file);
		$resp['image_id'] = $image ? $image->getId() : 0;
		$resp['is_processed'] = $image ? $image->getIsProcessed() : 0;
		$resp['error'] = $image ? $image->getError() : null;
		$resp['persons'] = array();

		$persons = $this->personMapper->findFromFile($this->userId, $fileId);
		foreach ($persons as $person) {
			$face = $this->faceMapper->getPersonOnFile($this->userId, $person->getId(), $fileId, $model);
			if (!count($face))
				continue;

			$facePerson = array();
			$facePerson['name'] = $person->getName();
			$facePerson['person_id'] = $person->getId();
			$facePerson['face'] = $face[0];

			$resp['persons'][] = $facePerson;
		}

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getFolderOptions(string $fullpath) {
		$userEnabled = $this->config->getUserValue($this->userId, 'facerecognition', 'enabled', 'false');

		$resp = array();
		if ($userEnabled !== 'true') {
			$resp['enabled'] = 'false';
			return new DataResponse($resp);
		}

		$folder = $this->fileService->getFileByPath($fullpath);

		$resp['enabled'] = 'true';
		$resp['is_allowed'] = $this->fileService->isAllowedNode($folder);
		$resp['parent_detection'] = !$this->fileService->isUnderNoDetection($folder);
		$resp['child_detection'] = $this->fileService->allowsChildDetection($folder);

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setFolderOptions(string $fullpath, bool $detection) {
		$folder = $this->fileService->getFileByPath($fullpath);
		$done = $this->fileService->setAllowChildDetection($folder, $detection);

		$resp = array();
		$resp['done'] = $done ? 'true' : 'false';
		$resp['child_detection'] = $detection ? 'true' : 'false';

		return $this->getFolderOptions($fullpath);
	}

}

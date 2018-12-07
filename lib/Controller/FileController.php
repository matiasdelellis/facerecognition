<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

class FileController extends Controller {

	private $config;

	private $personMapper;

	private $faceMapper;

	private $rootFolder;

	private $userId;

	public function __construct($AppName,
	                            IRequest     $request,
	                            IConfig      $config,
	                            PersonMapper $personMapper,
	                            FaceMapper   $faceMapper,
	                            IRootFolder  $rootFolder,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->config = $config;
		$this->personMapper = $personMapper;
		$this->faceMapper = $faceMapper;
		$this->rootFolder = $rootFolder;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getPersonsFromPath(string $fullpath) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$fileId = $userFolder->get($fullpath)->getId();

		$resp = array();
		$persons = $this->personMapper->findFromFile($this->userId, $fileId);
		foreach ($persons as $person) {
			$face = $this->faceMapper->getPersonOnFile($this->userId, $person->getId(), $fileId, $model);
			if (!count($face))
				continue;

			$facePerson = array();
			$facePerson['name'] = $person->getName();
			$facePerson['person_id'] = $person->getId();
			$facePerson['face'] = $face[0];

			$resp[] = $facePerson;
		}
		return new DataResponse($resp);
	}

}

<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\FaceNew;
use OCA\FaceRecognition\Db\FaceNewMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

class FileController extends Controller {

	private $personMapper;

	private $faceNewMapper;

	private $rootFolder;

	private $userId;

	public function __construct($AppName, IRequest $request,
	                            PersonMapper $personmapper,
	                            FaceNewMapper $facenewmapper,
	                            IRootFolder $rootFolder,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->personMapper = $personmapper;
		$this->faceNewMapper = $facenewmapper;
		$this->rootFolder = $rootFolder;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getPersonsFromPath(string $fullpath) {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$fileId = $userFolder->get($fullpath)->getId();

		$resp = array();
		$persons = $this->personMapper->findFromFile($this->userId, $fileId);
		foreach ($persons as $person) {
			$face = array();
			$face['name'] = $person->getName();
			$face['person_id'] = $person->getId();
			$face['face'] = $this->faceNewMapper->getPersonOnFile($this->userId, $person->getId(), $fileId, 1)[0];
			$resp[] = $face;
		}
		return new DataResponse($resp);
	}

}

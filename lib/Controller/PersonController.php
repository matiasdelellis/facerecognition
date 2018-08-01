<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

class PersonController extends Controller {

	private $rootFolder;
	private $faceMapper;
	private $userId;

	public function __construct($AppName, IRequest $request, IRootFolder $rootFolder, FaceMapper $facemapper, $UserId) {
		parent::__construct($AppName, $request);
		$this->rootFolder = $rootFolder;
		$this->faceMapper = $facemapper;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index() {
		$resp = array();
		$groups = $this->faceMapper->getGroups($this->userId);
		foreach ($groups as $group) {
			$resp[$group->getName()] = $this->faceMapper->findAllNamed($this->userId, $group->getName(), 12);
		}
		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $name
	 */
	public function getFaces($name) {
		$faces = $this->faceMapper->findAllNamed($this->userId, $name);
		return new DataResponse($faces);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $oldName
	 * @param string $newName
	 */
	public function updateName($name, $newName) {
		$faces = $this->faceMapper->findAllNamed($this->userId, $name);
		foreach ($faces as $face) {
			$face->setName($newName);
			$this->faceMapper->update($face);
		}
		$newFaces = $this->faceMapper->findAllNamed($this->userId, $newName);
		return new DataResponse($newFaces);
	}

}

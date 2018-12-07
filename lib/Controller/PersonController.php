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

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

class PersonController extends Controller {

	private $config;
	private $rootFolder;
	private $faceMapper;
	private $imageMapper;
	private $personMapper;
	private $userId;

	public function __construct($AppName,
	                            IRequest     $request,
	                            IConfig      $config,
	                            IRootFolder  $rootFolder,
	                            FaceMapper   $faceMapper,
	                            ImageMapper  $imageMapper,
	                            PersonMapper $personmapper,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->config = $config;
		$this->rootFolder = $rootFolder;
		$this->imageMapper = $imageMapper;
		$this->faceMapper = $faceMapper;
		$this->personMapper = $personmapper;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index() {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$resp = array();
		$persons = $this->personMapper->findAll($this->userId);
		foreach ($persons as $person) {
			$cluster = [];
			$faces = [];
			$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $model);
			foreach ($personFaces as $personFace) {
				$image = $this->imageMapper->find($this->userId, $personFace->getImage());
				$face = [];
				$face['id'] = $personFace->getId();
				$face['file-id'] = $image->getFile();
				$faces[] = $face;
			}
			$cluster['name'] = $person->getName();
			$cluster['id'] = $person->getId();
			$cluster['faces'] = $faces;
			$resp[] = $cluster;
		}
		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param string $name
	 */
	public function updateName($id, $name) {
		$person = $this->personMapper->find ($this->userId, $id);
		$person->setName($name);
		$this->personMapper->update($person);
		$newPerson = $this->personMapper->find($this->userId, $id);
		return new DataResponse($newPerson);
	}

}

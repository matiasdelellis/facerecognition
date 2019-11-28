<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\IURLGenerator;
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
use OCA\FaceRecognition\BackgroundJob\Tasks\StaleImagesRemovalTask;

class PersonController extends Controller {

	private $config;
	private $rootFolder;
	private $userSession;
	private $urlGenerator;
	private $faceMapper;
	private $imageMapper;
	private $personMapper;
	private $userId;

	public function __construct($AppName,
	                            IRequest      $request,
	                            IConfig       $config,
	                            IRootFolder   $rootFolder,
	                            IUserSession  $userSession,
	                            IURLGenerator $urlGenerator,
	                            FaceMapper    $faceMapper,
	                            ImageMapper   $imageMapper,
	                            PersonMapper  $personmapper,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->config       = $config;
		$this->rootFolder   = $rootFolder;
		$this->userSession  = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->imageMapper  = $imageMapper;
		$this->faceMapper   = $faceMapper;
		$this->personMapper = $personmapper;
		$this->userId       = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index() {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));
		$notGrouped = $this->config->getAppValue('facerecognition', 'show-not-grouped', 'false');
		$userEnabled = $this->config->getUserValue($this->userId, 'facerecognition', 'enabled', 'false');

		$resp = array();
		$resp['enabled'] = $userEnabled;
		$resp['clusters'] = array();

		if ($userEnabled === 'true') {
			$persons = $this->personMapper->findAll($this->userId);
			foreach ($persons as $person) {
				$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $model);
				if ($notGrouped === 'false' && count($personFaces) <= 1)
					continue;

				$limit = 14;
				$faces = [];
				foreach ($personFaces as $personFace) {
					if ($limit-- === 0)
						break;

					$image = $this->imageMapper->find($this->userId, $personFace->getImage());
					$fileUrl = $this->getRedirectToFileUrl($image->getFile());
					if (NULL === $fileUrl) {
						$limit++;
						continue;
					}

					$face = [];
					$face['thumb-url'] = $this->getThumbUrl($personFace->getId());
					$face['file-url'] = $fileUrl;
					$faces[] = $face;
				}

				$cluster = [];
				$cluster['name'] = $person->getName();
				$cluster['count'] = count($personFaces);
				$cluster['id'] = $person->getId();
				$cluster['faces'] = $faces;

				$resp['clusters'][] = $cluster;
			}
		}
		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 */
	public function find($id) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$person = $this->personMapper->find($this->userId, $id);

		$resp = [];
		$faces = [];
		$personFaces = $this->faceMapper->findFacesFromPerson($this->userId, $person->getId(), $model);
		foreach ($personFaces as $personFace) {
			$image = $this->imageMapper->find($this->userId, $personFace->getImage());
			$fileUrl = $this->getRedirectToFileUrl($image->getFile());
			if (NULL === $fileUrl)
				continue;
			$face = [];
			$face['thumb-url'] = $this->getThumbUrl($personFace->getId());
			$face['file-url'] = $fileUrl;
			$faces[] = $face;
		}
		$resp['name'] = $person->getName();
		$resp['id'] = $person->getId();
		$resp['faces'] = $faces;

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

	private function getThumbUrl($faceId) {
		$params = [];
		$params['id'] = $faceId;
		$params['size'] = 50;
		return $this->urlGenerator->linkToRoute('facerecognition.face.getThumb', $params);
	}

	private function getRedirectToFileUrl($fileId) {
		$uid        = $this->userSession->getUser()->getUID();
		$baseFolder = $this->rootFolder->getUserFolder($uid);
		$files      = $baseFolder->getById($fileId);
		$file       = current($files);

		if(!($file instanceof File)) {
			$this->config->setUserValue($this->userId, 'facerecognition', StaleImagesRemovalTask::STALE_IMAGES_REMOVAL_NEEDED_KEY, 'true');
			return NULL;
		}

		$params = [];
		$params['dir'] = $baseFolder->getRelativePath($file->getParent()->getPath());
		$params['scrollto'] = $file->getName();

		return $this->urlGenerator->linkToRoute('files.view.index', $params);
	}

}

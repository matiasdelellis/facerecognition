<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;

use OCP\IURLGenerator;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\FileService;

use OCA\FaceRecognition\Service\SettingsService;

class FileController extends Controller {

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var FileService */
	private $fileService;

	/** @var SettingsService */
	private $settingsService;

	/** @var string */
	private $userId;

	public function __construct($AppName,
	                            IURLGenerator   $urlGenerator,
	                            IRequest        $request,
	                            ImageMapper     $imageMapper,
	                            PersonMapper    $personMapper,
	                            FaceMapper      $faceMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->urlGenerator    = $urlGenerator;
		$this->imageMapper     = $imageMapper;
		$this->personMapper    = $personMapper;
		$this->faceMapper      = $faceMapper;
		$this->fileService     = $fileService;
		$this->settingsService = $settingsService;
		$this->userId          = $UserId;
	}

	/**
	 * @NoAdminRequired
	 *
	 * Get persons on file.
	 *
	 * @param string $fullpath of the file to get persons
	 * @return JSONResponse
	 */
	public function getPersonsFromPath(string $fullpath) {

		$resp = array();
		if (!$this->settingsService->getUserEnabled($this->userId)) {
			$resp['enabled'] = false;
			return new JSONResponse($resp);
		}

		$file = $this->fileService->getFileByPath($fullpath);

		$fileId = $file->getId();
		$modelId = $this->settingsService->getCurrentFaceModel();

		$image = $this->imageMapper->findFromFile($this->userId, $modelId, $fileId);

		$resp['enabled'] = true;
		$resp['is_allowed'] = $this->fileService->isAllowedNode($file);
		$resp['parent_detection'] = !$this->fileService->isUnderNoDetection($file);
		$resp['image_id'] = $image ? $image->getId() : 0;
		$resp['is_processed'] = $image ? $image->getIsProcessed() : false;
		$resp['error'] = $image ? $image->getError() : null;
		$resp['persons'] = array();

		$faces = $this->faceMapper->findFromFile($this->userId, $modelId, $fileId);
		foreach ($faces as $face) {
			// When there are faces but still dont have person, the process is not completed yet.
			// See issue https://github.com/matiasdelellis/facerecognition/issues/255
			if (!$face->getPerson()) {
				$resp['is_processed'] = false;
				break;
			}

			$person = $this->personMapper->find($this->userId, $face->getPerson());

			$facePerson = array();
			$facePerson['name'] = $person->getName();
			$facePerson['person_id'] = $person->getId();
			$facePerson['face_id'] = $face->getId();
			$facePerson['thumb_url'] = $this->getThumbUrl($face->getId());
			$facePerson['face_left'] = $face->getLeft();
			$facePerson['face_right'] = $face->getRight();
			$facePerson['face_top'] = $face->getTop();
			$facePerson['face_bottom'] = $face->getBottom();

			$resp['persons'][] = $facePerson;
		}

		return new JSONResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * Get if folder if folder is enabled
	 *
	 * @param string $fullpath of the folder
	 * @return JSONResponse
	 */
	public function getFolderOptions(string $fullpath) {
		$resp = array();

		if (!$this->settingsService->getUserEnabled($this->userId)) {
			$resp['enabled'] = false;
			return new JSONResponse($resp);
		}

		$folder = $this->fileService->getFileByPath($fullpath);

		$resp['enabled'] = 'true';
		$resp['is_allowed'] = $this->fileService->isAllowedNode($folder);
		$resp['parent_detection'] = !$this->fileService->isUnderNoDetection($folder);
		$resp['descendant_detection'] = $this->fileService->getDescendantDetection($folder);

		return new JSONResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 *
	 * Apply option to folder to enabled or disable it.
	 *
	 * @param string $fullpath of the folder.
	 * @param bool $detection
	 * @return JSONResponse
	 */
	public function setFolderOptions(string $fullpath, bool $detection) {
		$folder = $this->fileService->getFileByPath($fullpath);
		$this->fileService->setDescendantDetection($folder, $detection);

		return $this->getFolderOptions($fullpath);
	}

	/**
	 * Url to thumb face
	 *
	 * @param string $faceId face id to show
	 */
	private function getThumbUrl($faceId) {
		$params = [];
		$params['id'] = $faceId;
		$params['size'] = 32;
		return $this->urlGenerator->linkToRoute('facerecognition.face.getThumb', $params);
	}

}

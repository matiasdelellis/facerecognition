<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\FileService;

use OCA\FaceRecognition\Service\SettingsService;

class FileController extends Controller {

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
	                            IRequest        $request,
	                            ImageMapper     $imageMapper,
	                            PersonMapper    $personMapper,
	                            FaceMapper      $faceMapper,
	                            FileService     $fileService,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

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
		$image = $this->imageMapper->findFromFile($this->userId, $fileId);

		$resp['enabled'] = true;
		$resp['is_allowed'] = $this->fileService->isAllowedNode($file);
		$resp['parent_detection'] = !$this->fileService->isUnderNoDetection($file);
		$resp['image_id'] = $image ? $image->getId() : 0;
		$resp['is_processed'] = $image ? $image->getIsProcessed() : 0;
		$resp['error'] = $image ? $image->getError() : null;
		$resp['persons'] = array();

		$persons = $this->personMapper->findFromFile($this->userId, $fileId);
		foreach ($persons as $person) {
			$face = $this->faceMapper->getPersonOnFile($this->userId, $person->getId(), $fileId, $this->settingsService->getCurrentFaceModel());
			if (!count($face))
				continue;

			$facePerson = array();
			$facePerson['name'] = $person->getName();
			$facePerson['person_id'] = $person->getId();
			$facePerson['face'] = $face[0];

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

}

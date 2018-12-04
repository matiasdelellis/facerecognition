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

use OCA\FaceRecognition\Db\FaceNew;
use OCA\FaceRecognition\Db\FaceNewMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

class FaceController extends Controller {

	private $rootFolder;
	private $faceMapper;
	private $faceNewMapper;
	private $imageMapper;
	private $userId;

	public function __construct($AppName, IRequest $request, IRootFolder $rootFolder, FaceMapper $facemapper, FaceNewMapper $facenewmapper, ImageMapper $imagemapper, $UserId) {
		parent::__construct($AppName, $request);
		$this->rootFolder = $rootFolder;
		$this->faceMapper = $facemapper;
		$this->faceNewMapper = $facenewmapper;
		$this->imageMapper = $imagemapper;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 */
	 public function index() {
		$faces = $this->faceMapper->findAll($this->userId);
		return new DataResponse($faces);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 */
	public function find ($id) {
		$face = $this->faceMapper->find($this->userId, $id);
		return new DataResponse($face);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getThumb ($id) {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($this->userId);

		$face = $this->faceMapper->find($id, $this->userId);

		$fileId = $face->getFile();

		return $this->getFaceThumb ($fileId, $face);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getThumbV2 ($id) {
//		\OC_Util::tearDownFS();
//		\OC_Util::setupFS($this->userId);

		$face = $this->faceNewMapper->find($id);
		$image = $this->imageMapper->find($this->userId, $face->getImage());
		$fileId = $image->getFile();
		return $this->getFaceThumb ($fileId, $face);
	}

	private function getFaceThumb ($fileId, $face) {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$nodes = $userFolder->getById($fileId);
		$file = $nodes[0];

		$ownerView = new \OC\Files\View('/'. $this->userId . '/files');
		$path = $userFolder->getRelativePath($file->getPath());

		$img = new \OC_Image();
		$fileName = $ownerView->getLocalFile($path);
		$img->loadFromFile($fileName);

		$x = $face->getLeft ();
		$y = $face->getTop ();
		$w = $face->getRight () - $x;
		$h = $face->getBottom () - $y;

		$padding = $h*0.25;
		$x -= $padding;
		$y -= $padding;
		$w += $padding*2;
		$h += $padding*2;

		$img->crop($x, $y, $w, $h);
		$img->scaleDownToFit(64, 64);

		$resp = new DataDisplayResponse($img->data(), Http::STATUS_OK, ['Content-Type' => $img->mimeType()]);
		$resp->setETag((string)crc32($img->data()));
		$resp->cacheFor(7 * 24 * 60 * 60);
		$resp->setLastModified(new \DateTime('now', new \DateTimeZone('GMT')));

		return $resp;
	}

	/**
	 * @NoAdminRequired
	 *
	 */
	public function random () {
		$faces = $this->faceMapper->findRandom($this->userId);
		return new DataResponse($faces);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $fullpath
	 */
	public function findFile ($fullpath) {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$fileId = $userFolder->get($fullpath)->getId();
		$faces = $this->faceMapper->findFile($this->userId, $fileId);
		return new DataResponse($faces);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param string $newName
	 */
	public function updateName ($id, $newName) {
		$face = $this->faceMapper->find($id, $this->userId);
		$face->setName($newName);
		$face->setDistance(0.0);
		$newFace = $this->faceMapper->update($face);
		return new DataResponse($newFace);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 */
	public function invalidate($id) {
		$face = $this->faceMapper->find($id, $this->userId);
		$note->setDistance(1.0);
		$newFace = $this->faceMapper->update($face);
		return new DataResponse($newFace);
	}

}

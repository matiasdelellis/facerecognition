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

class FaceController extends Controller {

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
		$faces = $this->faceMapper->findAll($this->userId);
		return new DataResponse($faces);
	}

	/**
	 * @NoAdminRequired
	 */
	 public function getGroups() {
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
	 * @param int $fileId
	 */
	public function findFile ($fileId) {
		$faces = $this->faceMapper->findFile($this->userId, $fileId);
		return new DataResponse($faces);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $id
	 * @param string $name
	 */
	public function setName ($id, $name) {
		$face = $this->faceMapper->find($id, $this->userId);
		$face->setName($name);
		$note->setDistance(0.0);
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

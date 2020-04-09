<?php
namespace OCA\FaceRecognition\Controller;

use OCP\Image as OCP_Image;

use OCP\IRequest;
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

class FaceController extends Controller {

	private $rootFolder;
	private $faceMapper;
	private $imageMapper;
	private $userId;

	public function __construct($AppName,
	                            IRequest    $request,
	                            IRootFolder $rootFolder,
	                            FaceMapper  $faceMapper,
	                            ImageMapper $imageMapper,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->rootFolder = $rootFolder;
		$this->faceMapper = $faceMapper;
		$this->imageMapper = $imageMapper;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getThumb ($id, $size) {
		$face = $this->faceMapper->find($id);
		$image = $this->imageMapper->find($this->userId, $face->getImage());
		$fileId = $image->getFile();

		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$nodes = $userFolder->getById($fileId);
		$file = $nodes[0];

		$ownerView = new \OC\Files\View('/'. $this->userId . '/files');
		$path = $userFolder->getRelativePath($file->getPath());

		$img = new OCP_Image();
		$fileName = $ownerView->getLocalFile($path);
		$img->loadFromFile($fileName);
		$img->fixOrientation();

		$x = $face->getLeft ();
		$y = $face->getTop ();
		$w = $face->getRight () - $x;
		$h = $face->getBottom () - $y;

		$padding = $h*0.25;
		$x -= $padding;
		$y -= $padding;
		$w += $padding*2;
		$h += $padding*2;

		if (true) {
			$this->hipsterize($img, $face);
		}

		$img->crop($x, $y, $w, $h);
		$img->scaleDownToFit($size, $size);

		$resp = new DataDisplayResponse($img->data(), Http::STATUS_OK, ['Content-Type' => $img->mimeType()]);
		$resp->setETag((string)crc32($img->data()));
		$resp->cacheFor(7 * 24 * 60 * 60);
		$resp->setLastModified(new \DateTime('now', new \DateTimeZone('GMT')));

		return $resp;
	}

	/** @scrutinizer ignore-unused */
	private function hipsterize(&$image, &$face) {
		$imgResource = $image->resource();

		$landmarks = json_decode($face->getLandmarks(), true);
		if (count($landmarks) === 5) {
			$eyesX1 = $landmarks[2]['x'];
			$eyesY1 = $landmarks[2]['y'];

			$eyesX2 = $landmarks[0]['x'];
			$eyesY2 = $landmarks[0]['y'];

			$eyesXC = ($eyesX2 + $eyesX1)/2;
			$eyesYC = ($eyesY2 + $eyesY1)/2;

			$mustacheXC = $landmarks[4]['x'];
			$mustacheYC = $landmarks[4]['y'];
		}
		else if (count($landmarks) === 68) {
			$eyesX1 = $landmarks[36]['x'];
			$eyesY1 = $landmarks[36]['y'];
			$eyesX2 = $landmarks[45]['x'];
			$eyesY2 = $landmarks[45]['y'];

			$eyesXC = ($eyesX2 + $eyesX1)/2;
			$eyesYC = ($eyesY2 + $eyesY1)/2;

			$mustacheXC = $landmarks[52]['x'];
			$mustacheYC = $landmarks[52]['y'];
		}
		else {
			return;
		}

		$eyesW = $eyesX2 - $eyesX1;
		$eyesH = $eyesY2 - $eyesY1;

		$eyesL = sqrt(pow($eyesW, 2) + pow($eyesH, 2));
		$angle = rad2deg(atan(-$eyesH/$eyesW));

		$glassesGd = imagecreatefrompng(\OC_App::getAppPath('facerecognition') . '/img/glasses.png');
		if ($glassesGd === false)
			return;
		$fillColor = imagecolorallocatealpha($glassesGd, 0, 0, 0, 127);
		$glassesGd = imagerotate($glassesGd, $angle, $fillColor);

		$glassesW = imagesx($glassesGd);
		$glassesH = imagesy($glassesGd);

		$glassesRatio = $eyesL/$glassesW*1.5;

		$glassesDestX = intval($eyesXC - $glassesW * $glassesRatio / 2);
		$glassesDestY = intval($eyesYC - $glassesH * $glassesRatio / 2);
		$glassesDestW = intval($glassesW * $glassesRatio);
		$glassesDestH = intval($glassesH * $glassesRatio);

		imagecopyresized($imgResource, $glassesGd, $glassesDestX, $glassesDestY, 0, 0, $glassesDestW, $glassesDestH, $glassesW, $glassesH);

		$mustacheGd = imagecreatefrompng(\OC_App::getAppPath('facerecognition') . '/img/mustache.png');
		if ($mustacheGd === false)
			return;
		$fillColor = imagecolorallocatealpha($mustacheGd, 0, 0, 0, 127);
		$mustacheGd = imagerotate($mustacheGd, $angle, $fillColor);
		$mustacheW = imagesx($mustacheGd);
		$mustacheH = imagesy($mustacheGd);

		$mustacheRatio = $eyesL/$glassesW*1.1;

		$mustacheDestX = intval($mustacheXC - $mustacheW * $mustacheRatio / 2);
		$mustacheDestY = intval($mustacheYC - $mustacheH * $mustacheRatio / 2);
		$mustacheDestW = intval($mustacheW * $mustacheRatio);
		$mustacheDestH = intval($mustacheH * $mustacheRatio);

		imagecopyresized($imgResource, $mustacheGd, $mustacheDestX, $mustacheDestY, 0, 0, $mustacheDestW, $mustacheDestH, $mustacheW, $mustacheH);

		$image->setResource($imgResource);
	}

}

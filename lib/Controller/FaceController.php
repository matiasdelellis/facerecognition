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

use OCA\FaceRecognition\Service\FileCache;

class FaceController extends Controller {

	private $rootFolder;
	private $faceMapper;
	private $imageMapper;
	private $fileCache;
	private $userId;

	public function __construct($AppName,
	                            IRequest    $request,
	                            IRootFolder $rootFolder,
	                            FaceMapper  $faceMapper,
	                            ImageMapper $imageMapper,
	                            FileCache   $fileCache,
	                            $UserId)
	{
		parent::__construct($AppName, $request);
		$this->rootFolder = $rootFolder;
		$this->faceMapper = $faceMapper;
		$this->imageMapper = $imageMapper;
		$this->fileCache = $fileCache;
		$this->userId = $UserId;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return DataDisplayResponse
	 */
	public function getThumb (int $id, int $size) {

		$key = 'face-'. $this->userId . '-' .$id . '-' . $size . 'x' . $size;
		if ($this->fileCache->hasKey($key)) {
			$imgData = $this->fileCache->get($key);
			if (!is_null($imgData)) {
				$img = new OCP_Image();
				$img->loadFromData($imgData);
				return new DataDisplayResponse($img->data(), Http::STATUS_OK, ['Content-Type' => $img->mimeType()]);
			}
		}

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

		$img->crop($x, $y, $w, $h);
		$img->scaleDownToFit($size, $size);

		$this->fileCache->set($key, $img->data());

		return new DataDisplayResponse($img->data(), Http::STATUS_OK, ['Content-Type' => $img->mimeType()]);

	}

}

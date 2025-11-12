<?php
/**
 * @copyright Copyright (c) 2018-2020 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Controller;

use OCP\Image as OCP_Image;

use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCP\AppFramework\Db\Entity;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Service\SettingsService;

class FaceController extends Controller {

	/** @var IRootFolder */
	private $rootFolder;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var string */
	private $userId;

	public function __construct($AppName,
	                            IRequest        $request,
	                            IRootFolder     $rootFolder,
	                            FaceMapper      $faceMapper,
	                            ImageMapper     $imageMapper,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->rootFolder      = $rootFolder;
		$this->faceMapper      = $faceMapper;
		$this->imageMapper     = $imageMapper;
		$this->settingsService = $settingsService;
		$this->userId          = $UserId;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return DataDisplayResponse|JSONResponse
	 */
	public function getThumb ($id, $size) {
		$face = $this->faceMapper->find($id, $this->userId);
		if ($face === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$image = $this->imageMapper->find($this->userId, $face->getImage());
		if ($image === null) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

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

		$x = $face->getX();
		$y = $face->getY();
		$w = $face->getWidth();
		$h = $face->getHeight();

		$padding = $h*0.35;
		$x -= $padding;
		$y -= $padding;
		$w += $padding*2;
		$h += $padding*2;

		if ($this->settingsService->getObfuscateFaces()) {
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

	/**
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return DataDisplayResponse|JSONResponse
	 */
	public function getPersonThumb (string $name, int $size) {
		$modelId = $this->settingsService->getCurrentFaceModel();
		$personFace = current($this->faceMapper->findFromPerson($this->userId, $name, $modelId, 1));
		return $this->getThumb($personFace->getId(), $size);
	}

	/**
	 * @return void
	 */
	private function hipsterize(OCP_Image &$image, Entity &$face) {
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
		if ($glassesGd === false) return;

		$fillColor = imagecolorallocatealpha($glassesGd, 0, 0, 0, 127);
		$glassesGd = imagerotate($glassesGd, $angle, $fillColor);
		if ($glassesGd === false) return;

		$glassesW = imagesx($glassesGd);
		$glassesH = imagesy($glassesGd);

		$glassesRatio = $eyesL/$glassesW*1.5;

		$glassesDestX = intval($eyesXC - $glassesW * $glassesRatio / 2);
		$glassesDestY = intval($eyesYC - $glassesH * $glassesRatio / 2);
		$glassesDestW = intval($glassesW * $glassesRatio);
		$glassesDestH = intval($glassesH * $glassesRatio);

		imagecopyresized($imgResource, $glassesGd, $glassesDestX, $glassesDestY, 0, 0, $glassesDestW, $glassesDestH, $glassesW, $glassesH);

		$mustacheGd = imagecreatefrompng(\OC_App::getAppPath('facerecognition') . '/img/mustache.png');
		if ($mustacheGd === false) return;

		$fillColor = imagecolorallocatealpha($mustacheGd, 0, 0, 0, 127);
		$mustacheGd = imagerotate($mustacheGd, $angle, $fillColor);
		if ($mustacheGd === false) return;

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

<?php
namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

class ProcessController extends Controller {

	private $config;
	private $imageMapper;
	private $dateTimeFormatter;
	private $userId;

	public function __construct($AppName,
		                    IRequest $request,
		                    IConfig $config,
		                    ImageMapper $imageMapper,
		                    IDateTimeFormatter $dateTimeFormatter,
		                    $UserId)
		{
		parent::__construct($AppName, $request);
		$this->config = $config;
		$this->imageMapper = $imageMapper;
		$this->dateTimeFormatter = $dateTimeFormatter;
		$this->userId = $UserId;
	}

	/**
	 */
	public function index() {

		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// TODO: How to know the real state of the process?
		$status = true;

		$totalImages = $this->imageMapper->countImages($model);
		$processedImages = $this->imageMapper->countProcessedImages($model);
		$avgProcessingTime = $this->imageMapper->avgProcessingDuration($model);

		$estimatedTime = ($totalImages - $processedImages) * $avgProcessingTime/1000;

		$estimatedFinalize = $this->dateTimeFormatter->formatTimeSpan(time() + $estimatedTime);

		$params = array(
			'status' => $status,
			'estimatedFinalize' => $estimatedFinalize,
			'totalImages' => $totalImages,
			'processedImages' => $processedImages
		);

		return new JSONResponse($params);
	}

}

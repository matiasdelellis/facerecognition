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
		$status = ($this->config->getAppValue('facerecognition', 'pid', -1) > 0);

		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		$queueTotal = $this->imageMapper->countUserImages($this->userId, $model);
		$queueDone = $this->imageMapper->countUserProcessedImages($this->userId, $model);

		$endTime = 'Unknown';
		if ($queueDone > 0) {
			$startTime = $this->config->getAppValue('facerecognition', 'starttime', -1);
			$elapsedTime = time() - $startTime;
			$calcTime = time() + ($queueTotal - $queueDone)*$elapsedTime/$queueDone;
			$endTime = $this->dateTimeFormatter->formatTimeSpan($calcTime);
		}
		$params = array('status' => $status, 'endtime' => $endTime, 'queuetotal' => $queueTotal, 'queuedone' => $queueDone);

		return new JSONResponse($params);
	}

}

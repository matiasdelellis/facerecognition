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

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

class ProcessController extends Controller {

	private $config;
	private $faceMapper;
	private $dateTimeFormatter;
	private $userId;

	public function __construct($AppName,
		                    IRequest $request,
		                    IConfig $config,
		                    FaceMapper $facemapper,
		                    IDateTimeFormatter $dateTimeFormatter,
		                    $UserId) {
		parent::__construct($AppName, $request);
		$this->config = $config;
		$this->faceMapper = $facemapper;
		$this->dateTimeFormatter = $dateTimeFormatter;
		$this->userId = $UserId;
	}

	/**
	 */
	public function index() {
		$status = ($this->config->getAppValue('facerecognition', 'pid', -1) > 0);
		$queueTotal = $this->faceMapper->countQueue();
		$queueDone = $this->config->getAppValue('facerecognition', 'queue-done', 0);

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

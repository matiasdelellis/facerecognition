<?php

namespace OCA\FaceRecognition\Helper;

use OCP\App\IAppManager;

class PythonAnalyzer
{
	protected $command;
	protected $predictor;
	protected $model;
	protected $fileList;

	function __construct($command, $predictor, $model) {
		$this->command = $command;
		$this->predictor = $predictor;
		$this->model = $model;
		$this->fileList = "";
	}

	public function appendFile ($filename)
	{
		$this->fileList .= " ".$filename;
	}

	public function analyze ()
	{
		$cmd = $this->command.' analyze --predictor '.$this->predictor.' --model '.$this->model.' --search '.$this->fileList;
		$result = shell_exec ($cmd);
		$newFaces = json_decode ($result);
		$this->fileList = "";
		return $newFaces->{'faces-locations'};
	}
}

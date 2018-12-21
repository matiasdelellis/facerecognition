<?php

namespace OCA\FaceRecognition\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;

class Personal implements ISettings {

	/** @var IConfig */
	protected $config;

	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var IL10N */
	protected $l;

	/** @var string */
	protected $userId;

	public function __construct(IConfig     $config,
	                            IAppManager $appManager,
	                            IL10N       $l,
	                            $userId)
	{
		$this->config = $config;
		$this->appManager = $appManager;
		$this->l = $l;
		$this->userId = $userId;
	}

	public function getPriority()
	{
		return 20;
	}

	public function getSection()
	{
		return 'facerecognition';
	}

	public function getSectionID()
	{
		return 'facerecognition';
	}

	public function getForm()
	{
		$enabled = false;
		$value = $this->config->getUserValue($this->userId, 'facerecognition', 'enabled');
		if ($value !== '') {
			$enabled = $value === 'true' ? true : false;
		}

		$params = [
			'enabled' => $enabled
		];
		return new TemplateResponse('facerecognition', 'settings/personal', $params, '');
	}

	public function getPanel()
	{
		return $this->getForm();
	}

}
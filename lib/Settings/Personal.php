<?php

namespace OCA\FaceRecognition\Settings;

use OCA\Viewer\Event\LoadViewer;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;

class Personal implements ISettings {

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IConfig */
	protected $config;

	/** @var \OCP\App\IAppManager **/
	protected $appManager;

	/** @var IL10N */
	protected $l;

	public function __construct(IEventDispatcher $eventDispatcher,
	                            IConfig          $config,
	                            IAppManager      $appManager,
	                            IL10N            $l)
	{
		$this->eventDispatcher = $eventDispatcher;
		$this->config = $config;
		$this->appManager = $appManager;
		$this->l = $l;
	}

	public function getPriority()
	{
		return 20;
	}

	public function getSection()
	{
		return 'facerecognition';
	}

	public function getSectionID(): string
	{
		return 'facerecognition';
	}

	public function getForm()
	{
		$params = [];
		$this->eventDispatcher->dispatch(LoadViewer::class, new LoadViewer());
		return new TemplateResponse('facerecognition', 'settings/personal', $params, '');
	}

	public function getPanel(): TemplateResponse
	{
		return $this->getForm();
	}

}
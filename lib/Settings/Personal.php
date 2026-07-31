<?php

namespace OCA\FaceRecognition\Settings;

use OCA\Viewer\Event\LoadViewer;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

use OCA\FaceRecognition\Db\ClusterMapper;

use OCA\FaceRecognition\Service\SettingsService;

class Personal implements ISettings {

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var \OCP\AppFramework\Services\IInitialState **/
	protected IInitialState $initialState;

	/** @var ClusterMapper */
	protected $clusterMapper;

	/** @var SettingsService */
	protected $settingsService;

	protected ?string $userId;

	public function __construct(IEventDispatcher $eventDispatcher,
	                            IInitialState    $initialState,
	                            ClusterMapper    $clusterMapper,
	                            SettingsService  $settingsService,
	                            string           $userId)
	{
		$this->eventDispatcher = $eventDispatcher;
		$this->initialState = $initialState;
		$this->clusterMapper = $clusterMapper;
		$this->settingsService = $settingsService;
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

	public function getSectionID(): string
	{
		return 'facerecognition';
	}

	public function getForm()
	{
		$userEnabled = $this->settingsService->getUserEnabled($this->userId);
		$unamedCount = 0;
		$hiddenCount = 0;

		if ($userEnabled) {
			$modelId = $this->settingsService->getCurrentFaceModel();
			$minClusterSize = $this->settingsService->getMinimumFacesInCluster();

			$clusters = $this->clusterMapper->findUnassigned($this->userId, $modelId);
			foreach ($clusters as $cluster) {
				$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
				if ($clusterSize >= $minClusterSize)
					$unamedCount++;
			}

			$clusters = $this->clusterMapper->findIgnored($this->userId, $modelId);
			foreach ($clusters as $cluster) {
				$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
				if ($clusterSize >= $minClusterSize)
					$hiddenCount++;
			}
		}

		$this->initialState->provideInitialState('user-enabled', $userEnabled);
		$this->initialState->provideInitialState('has-unamed', $unamedCount > 0);
		$this->initialState->provideInitialState('has-hidden', $hiddenCount > 0);

		$this->eventDispatcher->dispatch(LoadViewer::class, new LoadViewer());
		return new TemplateResponse('facerecognition', 'settings/personal');
	}

	public function getPanel(): TemplateResponse
	{
		return $this->getForm();
	}

}
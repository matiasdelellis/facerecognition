<?php

namespace OCA\FaceRecognition\Settings;

use OCA\Viewer\Event\LoadViewer;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\ClusterMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Traits\LoggerTrait;
use Psr\Log\LoggerInterface;

class Personal implements ISettings {

	use LoggerTrait;

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
	                            ClusterMapper     $personmapper,
	                            SettingsService  $settingsService,
								LoggerInterface $logger,
	                            string           $userId)
	{
		$this->eventDispatcher = $eventDispatcher;
		$this->initialState = $initialState;
		$this->clusterMapper = $personmapper;
		$this->settingsService = $settingsService;
		$this->userId = $userId;
		$this->setLogger($logger);
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
		$unamedCount = false;
		$hiddenCount = false;
		$this->logInfo("Preparing Personal settings form, user " . $this->userId . " enabled: " . ($userEnabled ? "yes" : "no"));

		if ($userEnabled) {
			$modelId = $this->settingsService->getCurrentFaceModel();
			$minClusterSize = $this->settingsService->getMinimumFacesInCluster();
			$this->logInfo("Using model ID " . $modelId . " and minimum cluster size " . $minClusterSize . " for user " . $this->userId);
			$clusters = $this->clusterMapper->findUnassigned($this->userId, $modelId);
			foreach ($clusters as $cluster) {
				if (!$unamedCount) {
					$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
					if ($clusterSize >= $minClusterSize) {
						$unamedCount = true;
						$this->logInfo("Found unamed clusters for user " . $this->userId);
						break;
					}
				}
			}

			unset($clusters);
			$clusters = $this->clusterMapper->findIgnored($this->userId, $modelId);
			foreach ($clusters as $cluster) {
				if (!$hiddenCount) {
					$clusterSize = $this->clusterMapper->countClusterFaces($cluster->getId());
					if ($clusterSize >= $minClusterSize){
							$hiddenCount = true;
							$this->logInfo("Found hidden clusters for user " . $this->userId);
							break;
					}
				}
			}
		}

		$this->initialState->provideInitialState('user-enabled', $userEnabled);
		$this->initialState->provideInitialState('has-unamed', $unamedCount);
		$this->initialState->provideInitialState('has-hidden', $hiddenCount);

		$this->logDebug("Dispatching LoadViewer event from Personal settings");
		$this->eventDispatcher->dispatch(LoadViewer::class, new LoadViewer());
		return new TemplateResponse('facerecognition', 'settings/personal');
	}

	public function getPanel(): TemplateResponse
	{
		return $this->getForm();
	}

}
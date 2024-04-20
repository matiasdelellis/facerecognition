<?php
/**
 * @copyright Copyright (c) 2017-2020 Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use CurlHandle;
use OCP\Image as OCP_Image;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\ILockingProvider;
use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Helper\TempImage;
use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;
use OCA\FaceRecognition\Model\ExternalModel\ExternalModel;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Taks that get all images that are still not processed and processes them.
 * Processing image means that each image is prepared, faces extracted form it,
 * and for each found face - face descriptor is extracted.
 * 
 * The ImageProcessingWithMultipleExternalModelInstancesTask breaks up the
 * functionality of the ExternalModel class into smaller parts so they can be 
 * used to user multiple external model instances for parallel image analysis.
 */
class ImageProcessingWithMultipleExternalModelInstancesTask extends FaceRecognitionBackgroundTask {

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/** @var FileService */
	protected $fileService;

	/** @var SettingsService */
	protected $settingsService;

	/** @var ModelManager $modelManager */
	protected $modelManager;

	/** @var ILockingProvider $lockingProvider */
	protected ILockingProvider $lockingProvider;

	/** @var IModel $model */
	private $model;

	/** @var int|null $maxImageAreaCached Maximum image area (cached, so it is not recalculated for each image) */
	private $maxImageAreaCached;

	/** @var Array $preparedTasks Set of instances for which a task has been prepared */
	private $preparedTasks;

	/** @var Array $scheduledTasks Set of instances which are currently busy with a face recognition task */
	private $scheduledTasks;

	/** @var \CurlHandle[] $curlHandles All cURL handles */
	private $curlHandles;

	/** @var \CurlMultiHandle $curlMultiHandle The cURL multi handle */
	private $curlMultiHandle;

	/** @var String|null model api endpoint */
	private $modelUrl;

	/** @var String|null model api key */
	private $modelApiKey;

	/** @var bool the external model instances listen on the same address on suibsequent ports */
	private $modelConsecutivePorts;

	/** @var String a simple regular expression pattern that should capture the port in most cases */
	const PORT_REGEX_PATTERN = "/(.*?):(\d+)(\/.*)?/";


	/**
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FileService $fileService
	 * @param SettingsService $settingsService
	 * @param ModelManager $modelManager Model manager
	 * @param ILockingProvider $lockingProvider
	 */
	public function __construct(ImageMapper      $imageMapper,
	                            FileService      $fileService,
	                            SettingsService  $settingsService,
	                            ModelManager     $modelManager,
	                            ILockingProvider $lockingProvider)
	{
		parent::__construct();

		$this->imageMapper        = $imageMapper;
		$this->fileService        = $fileService;
		$this->settingsService    = $settingsService;
		$this->modelManager       = $modelManager;
		$this->lockingProvider    = $lockingProvider;

		$this->model              = null;
		$this->maxImageAreaCached = null;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Process all images to extract faces using multiple instances of the external model";
	}

	/**
	 * @inheritdoc
	 */
	public function cleanUpOnTimeout(): void {
		parent::cleanUpOnTimeout();

		// TODO (optional): Add an option and the required code to wait for all running analysis tasks to finish and save the results of those analyses.
		// Note: The current behavior is OK because the task will quite without much delay after timeout. 
		// 		 Waiting for the currently running analyses to finish woul dintroduce a significant extra delay during cleanup (depending on the speed of the external model).
		//		 This delay in turn might result in the task still running when the next cron job is started, and thus, missing the next execution window.
		//		 --> The user must account for this potential extra delay by shortening the timeout appropriately.
		//		 --> There should be an option that needs to be enabled explicitly if the running tasks should be finished during cleanup.

		// Clean up running tasks
		foreach($this->scheduledTasks as $task) {
			if(isset($task["curlHandle"])) {
				curl_multi_remove_handle($this->curlMultiHandle, $task["curlHandle"]);
			}
			if(isset($task["tempImage"])) {
				$task["tempImage"]->clean();
			}
			if(isset($task["lockKey"])) {
				$this->lockingProvider->releaseLock($task["lockKey"], $task["lockType"]);
			}
		}

		// Clean up prepared tasks
		foreach($this->preparedTasks as $task) {
			if(isset($task["tempImage"])) {
				$task["tempImage"]->clean();
			}
			if(isset($task["lockKey"])) {
				$this->lockingProvider->releaseLock($task["lockKey"], $task["lockType"]);
			}
		}

		// Close cURL handles
		foreach($this->curlHandles as $ch) {
			curl_close($ch);
		}

		// Close cURL multi-handle
		curl_multi_close($this->curlMultiHandle);		

		// If there are temporary files from external files, they must also be cleaned.
		$this->fileService->clean();

	}


	/**
	 * Copied from ExternalModel::detectFaces, special modification of CURLOPT_URL
	 */
	private function prepDetectFacesRequest(\CurlHandle $ch, string $imagePath, int $port = 0) {
		if ($ch === false) {
			throw new \Exception('Curl error: unable to initialize curl');
		}
		
		$cFile = curl_file_create($imagePath);
		$post = array('file'=> $cFile);
		$modelUrl = $this->modelUrl;

		if($port > 0) {
			$matches = [];
			if(preg_match(ImageProcessingWithMultipleExternalModelInstancesTask::PORT_REGEX_PATTERN, $modelUrl, $matches)) {
				$modelUrl = $matches[1] . ":" . ($port) . (count($matches) > 3 ? $matches[3] : '');
			} else {
				throw new \RuntimeException("Model URL (" . $modelUrl . ") does not specify a port.");
			}
		} 
		curl_setopt($ch, CURLOPT_URL, $modelUrl . '/detect');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $this->modelApiKey]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);		

		$this->logDebug("Request to external model prepared. URL: $modelUrl <-- $imagePath");
	}

	/**
	 * Copied from ExternalModel::detectFaces
	 */
	public function evalDetectFacesResponse($response): array {
		if (is_bool($response) or is_null($response)) {
			throw new \Exception('Invalid response: ' . var_export($response, true));
		}

		$jsonResponse = json_decode($response, true);

		if (!is_array($jsonResponse))
			return [];

		if ($jsonResponse['faces-count'] == 0)
			return [];

		return $jsonResponse['faces'];
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		$this->logInfo('NOTE: Starting face recognition. If you experience random crashes after this point, please look FAQ at https://github.com/matiasdelellis/facerecognition/wiki/FAQ');

		// Get current model.
		$this->model = $this->modelManager->getCurrentModel();

		// Open model.
		$this->model->open();

		// occ config:system:set facerecognition.external_model_number_of_instances --value 8

		if(!($this->model->getId() == ExternalModel::FACE_MODEL_ID and $this->settingsService->getExternalModelNumberOfInstances() > 1)) {
			throw new \RuntimeException("The ImageProcessingWithMultipleExternalModelInstancesTask requires that the external model is selected and that the number of external instances is greater than one.");
		}
			
		/*************************************************************************************************************************
		 * If the EXTERNAL MODEL is set and more than 2 instances are configured to be used, then we can parallelize face analysis.
		 */

		$this->modelUrl = $this->settingsService->getExternalModelUrl();
		$this->modelApiKey = $this->settingsService->getExternalModelApiKey();

		$nInstances = $this->settingsService->getExternalModelNumberOfInstances();
		$this->logInfo("NOTE: Using the EXTERNAL MODEL for face recognition.");
		if($nInstances > 1) {
			$this->logInfo("NOTE: Using $nInstances instances of the external model in parallel.");
		}

		// "free" instances are model instances without a prepared task.
		// "prepared" instances are model instances for which a task has been prepared
		// free / prepared is irrespective of whether the instance is idle or running
		$freeInstances = range(0, $nInstances-1);
		$preparedInstances = [];
		$instancePrepared = array_fill(0, $nInstances, false);
		// "idle" instances are model instances without assigned analysis request.
		$idleInstances = range(0, $nInstances-1);
		$activeInstances = [];
		$instanceActive =  array_fill(0, $nInstances, false); 
		
		$this->modelConsecutivePorts = $this->settingsService->getExternalModelInstancesHaveConsecutivePorts();

		$basePort = 80;
		$matches = [];
		if(preg_match(ImageProcessingWithMultipleExternalModelInstancesTask::PORT_REGEX_PATTERN, $this->modelUrl, $matches)) {
			$basePort = $matches[2];
		} else {
			if($this->modelConsecutivePorts) {
				$this->context->ncLogger->critical("The external model URL does not specify a port.", );
				$this->logInfo("FATAL: The external model URL does not specify a port.");
				return false;
			}
		}

		if($this->modelConsecutivePorts) {
			for($i = 0; $i < $nInstances; $i++) {
				$this->logDebug("- Instance $i: " . $matches[1] . ":" . ($basePort+$i) . (count($matches) > 3 ? $matches[3] : ''));
			}
		}

		// Initialize cURL handles
		$nCurlHandles = 2 * $nInstances;
		$chs = []; // curl handles
		$this->curlHandles = $chs;
		$unusedCurlHandles = range(0, $nCurlHandles-1);
		try {
			for($i = 0; $i < $nCurlHandles; $i++) {
				$ch = curl_init();
				if ($ch === false) {
					throw new \Exception('Curl error: unable to initialize curl');
				}
				$chs[$i] = $ch;
			}

		} catch (\Exception $e) {
			$this->context->ncLogger->critical('Image processing aborted due to the following error: ' . $e->getMessage());
			$this->logInfo('FATAL: Image processing aborted due to the following error: ' . $e->getMessage());
			$this->logDebug((string) $e);

			for($i=0; $i < sizeof($chs); $i++) {
				curl_close($chs[$i]);
			}

			return false;
		}

		// Create the cURL multi-handle
		$mh = curl_multi_init();
		$this->curlMultiHandle = $mh;


		$this->preparedTasks = [];
		$this->scheduledTasks = [];


		// Do the actual image recognition task
		$context = $this->context;
		$images = $context->propertyBag['images'];
		
		do {

			yield;

			/**====================================================================================================
			 * Prepare task
			 */

			// Check if we need to prepare an image for analysis
			if(count($freeInstances) > 0) {

				// Get the current element from the beginning of the array
				$image = current($images);

				// Check if there are still elements in $images
				if ($image) {

					// Move the array pointer to the next element
					next($images);

					/**
					 * Start copy&paste from ImageProcessingTask::execute
					 */

					// Get a image lock
					$lockKey = 'facerecognition/' . $image->getId();
					$lockType = ILockingProvider::LOCK_EXCLUSIVE;

					try {
						$this->lockingProvider->acquireLock($lockKey, $lockType);
					} catch (\OCP\Lock\LockedException $e) {
						$this->logInfo('Faces found: 0. Image ' . $image->getId() . ' will be skipped because it is locked');
						continue;
					}

					$dbImage = $this->imageMapper->find($image->getUser(), $image->getId());
					if ($dbImage->getIsProcessed()) {
						$this->logInfo('Faces found: 0. Image will be skipped since it was already processed.');
						// Release lock of file.
						$this->lockingProvider->releaseLock($lockKey, $lockType);
						continue;
					}

					// Get an temp Image to process this image.
					$tempImage = $this->getTempImage($image);

					if (is_null($tempImage)) {
						// If we cannot find a file probably it was deleted out of our control and we must clean our tables.
						$this->settingsService->setNeedRemoveStaleImages(true, $image->user);
						$this->logInfo('File with ID ' . $image->file . ' doesn\'t exist anymore, skipping it');
						// Release lock of file.
						$this->lockingProvider->releaseLock($lockKey, $lockType);
						continue;
					}

					if ($tempImage->getSkipped() === true) {
						$this->logInfo('Faces found: 0 (image will be skipped because it is too small)');
						$this->imageMapper->imageProcessed($image, array(), 0);
						// Release lock of file.
						$this->lockingProvider->releaseLock($lockKey, $lockType);
						continue;
					}

					// Get faces in the temporary image
					$tempImagePath = $tempImage->getTempPath();
					
					/**
					 * End copy&paste from ImageProcessingTask::execute
					 */
					

					
					$instance = -1;
					// Select an actual instance to which the task will be assigned
					// First, check if there are and idle instances for which there are no prepared tasks
					if(count($idleInstances) > 0) {
						foreach($idleInstances as $i) {
							if(!array_key_exists($i, $preparedInstances)) {
								$instance = $i;
								break;
							}
						}
					}
					// If there are no idling instances without prepared tasksm, the we can just take the first free instance
					if($instance == -1) {
						$instance = array_key_first($freeInstances);	// take the first free instance
					} 
					$ich = array_shift($unusedCurlHandles);		// take the first unused handle
					$ch = $chs[$ich];							// get the actual handle

					// Prepare the cURL request.
					if($this->modelConsecutivePorts) {
						// External Model instances have different, consecutive ports --> modify URL
						$this->prepDetectFacesRequest($ch, $tempImagePath, $basePort+$instance);
					} else {
						// External Model instances are behind a load balancer, we always call the same URL.
						$this->prepDetectFacesRequest($ch, $tempImagePath);
					}

					
					// put all the info into an array for later use
					$task = [];
					$task["image"] = $image;
					$task["lockKey"] = $lockKey;
					$task["lockType"] = $lockType;
					$task["tempImage"] = $tempImage;
					$task["instance"] = $instance;
					$task["iCurlHandle"] = $ich;
					$task["curlHandle"] = $ch;

					if(array_key_exists($instance, $preparedInstances)) {
						$this->context->ncLogger->error("Instance #$instance already has a prepared task (for image #" . $this->preparedTasks[$instance]["image"]->getId() . 
						") which will be overwritten by a new task for image " . $image->getId() . ". This should not have happened :-(", $this->preparedTasks[$instance]);
					}
					unset($freeInstances[$instance]);				// instance is no longer free
					$preparedInstances[$instance] = $instance;		// add instance index to array to indicate that a task has been prepared for that instance
					$this->preparedTasks[$instance] = $task;		// add task to array of prepared tasks

					$this->logDebug("Image prepared for analysis by instance #$instance:  " . $task["tempImage"]->getImagePath());
				}
			}
			// End of "Prepare task"
			// ----------------------------------------------------------------------------------------------------



			/**====================================================================================================
			 * Fill queue
			 */

			// Check if there are idling instances
			if(count($idleInstances) > 0) {
				$instance = -1;
				foreach($idleInstances as $i) {
					if(array_key_exists($i, $preparedInstances)) {
						$instance = $i;
						break;
					}
				}
				if($instance < 0) {
					$this->logDebug("No task prepared for any of the idle instances [" . implode(", ", $idleInstances) . "] --> looks like we're almost done.");
					// by the logic implemented in "prepare tasks" above, the next image analysis task should be assigned to an idling instance --> nothing to do here
				} else {

					$task = $this->preparedTasks[$instance];		// get task
					$this->scheduledTasks[$instance] = $task;		// add to scheduled tasks
					unset($this->preparedTasks[$instance]);			// remove from prepared tasks

					unset($preparedInstances[$instance]);			// Remove instance from list of prepared instances, and
					$freeInstances[$instance] = $instance;			// mark instance as free.
					
					unset($idleInstances[$instance]);				// Remove instance from list of idle instances, and
					$activeInstances[$instance] = $instance;		// mark instance as active.
					
					$ch = $task["curlHandle"];
					$this->scheduledTasks[$instance]["startMillis"] = round(microtime(true) * 1000);

					$this->logDebug("Image scheduled for analysis by instance #$instance: " . $task["tempImage"]->getImagePath());

					// ad cURL handle to multi handle --> request will be executed
					curl_multi_add_handle($mh, $ch);
				}

			}
			// End of "Fill queue"
			// ----------------------------------------------------------------------------------------------------
			



			// Execute analysis requests
			curl_multi_exec($mh, $active);

			if ($active) {
				// Wait a short time for more activity
				curl_multi_select($mh);
			}

			// see https://stackoverflow.com/questions/75286863/process-curl-multi-exec-results-while-in-progress
			// Consume any completed transfers
			$curlMultiInfoRead = [];
			while ($curlMultiInfoRead = curl_multi_info_read($mh, $queued_messages)) {

				$ch = $curlMultiInfoRead['handle'];
				// Check CurlHandle has not had an error

				$this->logDebug("cURL result available: return code " . $curlMultiInfoRead["result"]);
				
				// Identify which of the tasks has been ompleted
				unset($task);
				for($instance = 0; $instance < $nInstances; $instance++) {
					if($this->scheduledTasks[$instance]["curlHandle"] == $ch) {
						$task = $this->scheduledTasks[$instance];
						break;
					}
				}
				
				// Oops
				if(!isset($task)) {
					$this->context->ncLogger->error("cURL handle " . var_export($ch) . " does not correspond to any scheduled task. This should not have happened :-(", $this->scheduledTasks);
					curl_close($ch);	// close handle, we don't know this handle...
					curl_multi_remove_handle($mh, $ch);
					continue;
				}

				if($curlMultiInfoRead['result'] === CURLE_GOT_NOTHING) {
					$this->context->ncLogger->warning("The external model instance #" . $task["instance"] . " returned no result for image " . $task["tempImage"]->getImagePath() . ". This could be a sign of (out of) memory issues of the host that runs your external model. Reduce the number of model instances (if hosted on the same machine) or increase the available RAM of your external model.", $task);
					curl_multi_remove_handle($mh, $ch);
					curl_multi_add_handle($mh, $ch);
					continue;

				} elseif($curlMultiInfoRead['result'] === CURLE_COULDNT_CONNECT) {
					$this->context->ncLogger->warning("Couldn't connect to model instance #" . $task["instance"] . ". Retrying... Note: This could be a sign of the extrenal model instance being killed due to memory issues (typical for docker deployment). Reduce the number of model instances (if hosted on the same machine) or increase the available RAM of your external model.", $task);
					curl_multi_remove_handle($mh, $ch);
					curl_multi_add_handle($mh, $ch);
					continue;
				} elseif($curlMultiInfoRead['result'] !== CURLE_OK) {
					throw new \RuntimeException(curl_error($ch));
				}

				$startMillis = $task["startMillis"];
				$image = $task["image"];
				$tempImage = $task["tempImage"];
				$lockKey = $task["lockKey"];
				$lockType = $task["lockType"];
				$ich = $task["iCurlHandle"];

				$this->logDebug("Analysis of " . $tempImage->getImagePath() . " completed.");
				

				$response = curl_multi_getcontent($ch);
				if (is_bool($response) or is_null($response)) {
					$this->logInfo('Response ' . var_export($response, true) . " for " . $tempImage->getImagePath());
					$tempImage->clean();
					curl_multi_remove_handle($mh, $ch);
					continue;
					
				} elseif(empty($response)) {
					$this->logInfo("Response is EMPTY for " . $tempImage->getImagePath() . " --> image is skipped and will be retried in a later iteration of background_job.");
					$this->logInfo("This could be a sign for insufficient memory on the machine running the external model. Provide at least 1G per instance.");
					$tempImage->clean();
					curl_multi_remove_handle($mh, $ch);
					continue;
				}

				$info = curl_getinfo($ch);

				// evaluate response
				$rawFaces = $this->evalDetectFacesResponse($response);
				$this->logInfo('Faces found: ' . count($rawFaces) . " by " . $info['url'] . " in " . $tempImage->getImagePath());

				
				$faces = array();
				foreach ($rawFaces as $rawFace) {
					// Normalize face and landmarks from model to original size
					$normFace = $this->getNormalizedFace($rawFace, $tempImage->getRatio());
					// Convert from dictionary of face to our Face Db Entity.
					$face = Face::fromModel($image->getId(), $normFace);
					// Save the normalized Face to insert on database later.
					$faces[] = $face;
				}

				// Save new faces fo database
				$endMillis = round(microtime(true) * 1000);
				$duration = (int) (max($endMillis - $startMillis, 0) / $nInstances);
				$this->imageMapper->imageProcessed($image, $faces, $duration);

				// Clean temporary image.
				$tempImage->clean();

				// Release lock of file.
				$this->lockingProvider->releaseLock($lockKey, $lockType);

				// Remove cURL handle from multi-handle
				curl_multi_remove_handle($mh, $ch);
				array_push($unusedCurlHandles, $ich);

				unset($activeInstances[$instance]);				// Remove instance from list of active instances, and
				$idleInstances[$instance] = $instance;			// mark instance as idle.

			}

		} while(current($images) or count($activeInstances) > 0 or count($preparedInstances) > 0);

		// Close cURL handles
		foreach($chs as $ch) {
			curl_close($ch);
		}

		// Close cURL multi-handle
		curl_multi_close($mh);

		// If there are temporary files from external files, they must also be cleaned.
		$this->fileService->clean();

		$this->logInfo('NOTE: Face recognition task finished, all images analyzed.');
		$this->context->ncLogger->info('Face recognition task finished, all images analyzed.');

		return true;
	}

	/**
	 * Given an image, build a temporary image to perform the analysis
	 *
	 * return TempImage|null
	 */
	private function getTempImage(Image $image): ?TempImage {
		// todo: check if this hits I/O (database, disk...), consider having lazy caching to return user folder from user
		$file = $this->fileService->getFileById($image->getFile(), $image->getUser());
		if (empty($file)) {
			return null;
		}

		if (!$this->fileService->isAllowedNode($file)) {
			return null;
		}

		$imagePath = $this->fileService->getLocalFile($file);
		if ($imagePath === null)
			return null;

		$this->logInfo('Processing image ' . $imagePath);

		$tempImage = new TempImage($imagePath,
		                           $this->model->getPreferredMimeType(),
		                           $this->getMaxImageArea(),
		                           $this->settingsService->getMinimumImageSize());

		return $tempImage;
	}

	/**
	 * Obtains max image area lazily (from cache, or calculates it and puts it to cache)
	 *
	 * @return int Max image area (in pixels^2)
	 */
	private function getMaxImageArea(): int {
		// First check if is cached
		//
		if (!is_null($this->maxImageAreaCached)) {
			return $this->maxImageAreaCached;
		}

		// Get this setting on main app_config.
		// Note that this option has lower and upper limits and validations
		$this->maxImageAreaCached = $this->settingsService->getAnalysisImageArea();

		// Check if admin override it in config and it is valid value
		//
		$maxImageArea = $this->settingsService->getMaximumImageArea();
		if ($maxImageArea > 0) {
			$this->maxImageAreaCached = $maxImageArea;
		}
		// Also check if we are provided value from command line.
		//
		if ((array_key_exists('max_image_area', $this->context->propertyBag)) &&
		    (!is_null($this->context->propertyBag['max_image_area']))) {
			$this->maxImageAreaCached = $this->context->propertyBag['max_image_area'];
		}

		return $this->maxImageAreaCached;
	}

	/**
	 * Helper method, to normalize face sizes back to original dimensions, based on ratio
	 *
	 */
	private function getNormalizedFace(array $rawFace, float $ratio): array {
		$face = [];
		$face['left'] = intval(round($rawFace['left']*$ratio));
		$face['right'] = intval(round($rawFace['right']*$ratio));
		$face['top'] = intval(round($rawFace['top']*$ratio));
		$face['bottom'] = intval(round($rawFace['bottom']*$ratio));
		$face['detection_confidence'] = $rawFace['detection_confidence'];
		$face['landmarks'] = $this->getNormalizedLandmarks($rawFace['landmarks'], $ratio);
		$face['descriptor'] = $rawFace['descriptor'];
		return $face;
	}

	/**
	 * Helper method, to normalize landmarks sizes back to original dimensions, based on ratio
	 *
	 */
	private function getNormalizedLandmarks(array $rawLandmarks, float $ratio): array {
		$landmarks = [];
		foreach ($rawLandmarks as $rawLandmark) {
			$landmark = [];
			$landmark['x'] = intval(round($rawLandmark['x']*$ratio));
			$landmark['y'] = intval(round($rawLandmark['y']*$ratio));
			$landmarks[] = $landmark;
		}
		return $landmarks;
	}

}
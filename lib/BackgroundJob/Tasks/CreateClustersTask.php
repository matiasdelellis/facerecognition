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

use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\Euclidean;

use OCA\FaceRecognition\Service\SettingsService;
/**
 * Taks that, for each user, creates person clusters for each.
 */
class CreateClustersTask extends FaceRecognitionBackgroundTask {
	/** @var PersonMapper Person mapper*/
	private $personMapper;

	/** @var ImageMapper Image mapper*/
	private $imageMapper;

	/** @var FaceMapper Face mapper*/
	private $faceMapper;

	/** @var SettingsService Settings service*/
	private $settingsService;

	/**
	 * @param PersonMapper $personMapper
	 * @param ImageMapper $imageMapper
	 * @param FaceMapper $faceMapper
	 * @param SettingsService $settingsService
	 */
	public function __construct(PersonMapper    $personMapper,
	                            ImageMapper     $imageMapper,
	                            FaceMapper      $faceMapper,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->personMapper    = $personMapper;
		$this->imageMapper     = $imageMapper;
		$this->faceMapper      = $faceMapper;
		$this->settingsService = $settingsService;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Create new persons or update existing persons";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		// We cannot yield inside of Closure, so we need to extract all users and iterate outside of closure.
		// However, since we don't want to do deep copy of IUser, we keep only UID in this array.
		//
		$eligable_users = array();
		if (is_null($this->context->user)) {
			$this->context->userManager->callForSeenUsers(function (IUser $user) use (&$eligable_users) {
				$eligable_users[] = $user->getUID();
			});
		} else {
			$eligable_users[] = $this->context->user->getUID();
		}

		foreach($eligable_users as $user) {
			$this->createClusterIfNeeded($user);
			yield;
		}

		return true;
	}

	/**
	 * @return void
	 */
	private function createClusterIfNeeded(string $userId) {
		$modelId = $this->settingsService->getCurrentFaceModel();

		// Depending on whether we already have clusters, decide if we should create/recreate them.
		//
		$hasPersons = $this->personMapper->countPersons($userId, $modelId) > 0;
		if ($hasPersons) {
			$forceRecreate = $this->needRecreateBySettings($userId);
			$haveEnoughFaces = $this->hasNewFacesToRecreate($userId, $modelId);
			$haveStaled = $this->hasStalePersonsToRecreate($userId, $modelId);

			if ($forceRecreate) {
				$this->logInfo('Clusters already exist, but there was some change that requires recreating the clusters');
			}
			else if ($haveEnoughFaces || $haveStaled) {
				$this->logInfo('Face clustering will be recreated with new information or changes');
			}
			else {
				// If there is no invalid persons, and there is no recent new faces, no need to recreate cluster
				$this->logInfo('Clusters already exist, estimated there is no need to recreate them');
				return;
			}
		}
		else {
			// User should not be able to use this directly, used in tests
			$forceTestCreation = $this->settingsService->_getForceCreateClusters($userId);
			$needCreate = $this->needCreateFirstTime($userId, $modelId);

			if ($forceTestCreation) {
				$this->logInfo('Force the creation of clusters for testing');
			}
			else if ($needCreate) {
				$this->logInfo('Face clustering will be created for the first time.');
			}
			else {
				$this->logInfo(
					'Skipping cluster creation, not enough data (yet) collected. ' .
					'For cluster creation, you need either one of the following:');
				$this->logInfo('* have 1000 faces already processed');
				$this->logInfo('* or you need to have 95% of you images processed');
				$this->logInfo('Use stats command to track progress');
				return;
			}
		}

		// Ok. If we are here, the clusters must be recreated.
		//
		$faces = $this->faceMapper->getFaces($userId, $modelId);
		$this->logInfo(count($faces) . ' faces found for clustering');

		// Cluster is associative array where key is person ID.
		// Value is array of face IDs. For old clusters, person IDs are some existing person IDs,
		// and for new clusters is whatever chinese whispers decides to identify them.
		//
		$currentClusters = $this->getCurrentClusters($faces);
		$newClusters = $this->getNewClusters($faces);
		$this->logInfo(count($newClusters) . ' persons found after clustering');

		// New merge
		$mergedClusters = $this->mergeClusters($currentClusters, $newClusters);
		$this->personMapper->mergeClusterToDatabase($userId, $currentClusters, $mergedClusters);

		// Remove all orphaned persons (those without any faces)
		// NOTE: we will do this for all models, not just for current one, but this is not problem.
		$orphansDeleted = $this->personMapper->deleteOrphaned($userId);
		if ($orphansDeleted > 0) {
			$this->logInfo('Deleted ' . $orphansDeleted . ' persons without faces');
		}

		// Prevents not create/recreate the clusters unnecessarily.

		$this->settingsService->setNeedRecreateClusters(false, $userId);
		$this->settingsService->_setForceCreateClusters(false, $userId);
	}

	/**
	 * Evaluate whether we want to recreate clusters. We want to recreate clusters/persons if:
	 * - Some cluster/person is invalidated (is_valid is false for someone)
	 *   - This means some image that belonged to this user is changed, deleted etc.
	 * - There are some new faces. Now, we don't want to jump the gun here. We want to either have:
	 *   - more than 25 new faces, or
	 *   - less than 25 new faces, but they are older than 2h
	 *
	 * (basically, we want to avoid recreating cluster for each new face being uploaded,
	 *  however, we don't want to wait too much as clusters could be changed a lot)
	 */
	private function hasNewFacesToRecreate(string $userId, int $modelId): bool {
		//
		$facesWithoutPersons = $this->faceMapper->countFaces($userId, $modelId, true);
		$this->logDebug(sprintf('Found %d faces without associated persons for user %s and model %d',
		                $facesWithoutPersons, $userId, $modelId));

		// todo: get rid of magic numbers (move to config)
		if ($facesWithoutPersons === 0)
			return false;

		if ($facesWithoutPersons >= 25)
			return true;

		// We have some faces, but not that many, let's see when oldest one is generated.
		$oldestFace = $this->faceMapper->getOldestCreatedFaceWithoutPerson($userId, $modelId);
		$oldestFaceTimestamp = $oldestFace->creationTime->getTimestamp();
		$currentTimestamp = (new \DateTime())->getTimestamp();
		$this->logDebug(sprintf('Oldest face without persons for user %s and model %d is from %s',
		                $userId, $modelId, $oldestFace->creationTime->format('Y-m-d H:i:s')));

		// todo: get rid of magic numbers (move to config)
		if ($currentTimestamp - $oldestFaceTimestamp > 2 * 60 * 60)
			return true;

		return false;
	}

	private function hasStalePersonsToRecreate(string $userId, int $modelId): bool {
		return $this->personMapper->countPersons($userId, $modelId, true) > 0;
	}

	private function needRecreateBySettings(string $userId): bool {
		return $this->settingsService->getNeedRecreateClusters($userId);
	}

	private function needCreateFirstTime(string $userId, int $modelId): bool {
		// User should not be able to use this directly, used in tests
		if ($this->settingsService->_getForceCreateClusters($userId))
			return true;

		$imageCount = $this->imageMapper->countUserImages($userId, $modelId);
		if ($imageCount === 0)
			return false;

		$imageProcessed = $this->imageMapper->countUserImages($userId, $modelId, true);
		if ($imageProcessed === 0)
			return false;

		// These are basic criteria without which we should not even consider creating clusters.
		// These clusters will be small and not "stable" enough and we should better wait for more images to come.
		// todo: get rid of magic numbers (move to config)
		$facesCount = $this->faceMapper->countFaces($userId, $modelId);
		if ($facesCount > 1000)
			return true;

		$percentImagesProcessed = $imageProcessed / floatval($imageCount);
		if ($percentImagesProcessed > 0.95)
			return true;

		return false;
	}

	private function getCurrentClusters(array $faces): array {
		$chineseClusters = array();
		foreach($faces as $face) {
			if ($face->person !== null) {
				if (!isset($chineseClusters[$face->person])) {
					$chineseClusters[$face->person] = array();
				}
				$chineseClusters[$face->person][] = $face->id;
			}
		}
		return $chineseClusters;
	}

	private function getNewClusters(array $faces): array {
		// Clustering parameters
		$sensitivity = $this->settingsService->getSensitivity();
		$min_confidence = $this->settingsService->getMinimumConfidence();
		$min_face_size = $this->settingsService->getMinimumFaceSize();

		// Create edges for chinese whispers
		$edges = array();

		if (version_compare(phpversion('pdlib'), '1.0.2', '>=')) {
			$faces_count = count($faces);
			for ($i = 0; $i < $faces_count; $i++) {
				$face1 = $faces[$i];
				if (($face1->confidence < $min_confidence) ||
				    (max($face1->height(), $face1->width()) < $min_face_size)) {
					$edges[] = array($i, $i);
					continue;
				}
				for ($j = $i; $j < $faces_count; $j++) {
					$face2 = $faces[$j];
					if (($face2->confidence < $min_confidence) ||
					    (max($face2->height(), $face2->width()) < $min_face_size)) {
						continue;
					}
					$distance = dlib_vector_length($face1->descriptor, $face2->descriptor);
					if ($distance < $sensitivity) {
						$edges[] = array($i, $j);
					}
				}
			}
		} else {
			$faces_count = count($faces);
			for ($i = 0; $i < $faces_count; $i++) {
				$face1 = $faces[$i];
				if (($face1->confidence < $min_confidence) ||
				    (max($face1->height(), $face1->width()) < $min_face_size)) {
					$edges[] = array($i, $i);
					continue;
				}
				for ($j = $i; $j < $faces_count; $j++) {
					$face2 = $faces[$j];
					if (($face2->confidence < $min_confidence) ||
					    (max($face2->height(), $face2->width()) < $min_face_size)) {
						continue;
					}
					$distance = Euclidean::distance($face1->descriptor, $face2->descriptor);
					if ($distance < $sensitivity) {
						$edges[] = array($i, $j);
					}
				}
			}
		}

		$newChineseClustersByIndex = dlib_chinese_whispers($edges);
		$newClusters = array();
		for ($i = 0, $c = count($newChineseClustersByIndex); $i < $c; $i++) {
			if (!isset($newClusters[$newChineseClustersByIndex[$i]])) {
				$newClusters[$newChineseClustersByIndex[$i]] = array();
			}
			$newClusters[$newChineseClustersByIndex[$i]][] = $faces[$i]->id;
		}

		return $newClusters;
	}

	/**
	 * todo: only reason this is public is because of tests. Go figure it out better.
	 */
	public function mergeClusters(array $oldCluster, array $newCluster): array {
		// Create map of face transitions
		$transitions = array();
		foreach ($newCluster as $newPerson=>$newFaces) {
			foreach ($newFaces as $newFace) {
				$oldPersonFound = null;
				foreach ($oldCluster as $oldPerson => $oldFaces) {
					if (in_array($newFace, $oldFaces)) {
						$oldPersonFound = $oldPerson;
						break;
					}
				}
				$transitions[$newFace] = array($oldPersonFound, $newPerson);
			}
		}
		// Count transitions
		$transitionCount = array();
		foreach ($transitions as $transition) {
			$key = $transition[0] . ':' . $transition[1];
			if (array_key_exists($key, $transitionCount)) {
				$transitionCount[$key]++;
			} else {
				$transitionCount[$key] = 1;
			}
		}
		// Create map of new person -> old person transitions
		$newOldPersonMapping = array();
		$oldPersonProcessed = array(); // store this, so we don't waste cycles for in_array()
		arsort($transitionCount);
		foreach ($transitionCount as $transitionKey => $count) {
			$transition = explode(":", $transitionKey);
			$oldPerson = intval($transition[0]);
			$newPerson = intval($transition[1]);
			if (!array_key_exists($newPerson, $newOldPersonMapping)) {
				if (($oldPerson === 0) || (!array_key_exists($oldPerson, $oldPersonProcessed))) {
					$newOldPersonMapping[$newPerson] = $oldPerson;
					$oldPersonProcessed[$oldPerson] = 0;
				} else {
					$newOldPersonMapping[$newPerson] = 0;
				}
			}
		}
		// Starting with new cluster, convert all new person IDs with old person IDs
		$maxOldPersonId = 1;
		if (count($oldCluster) > 0) {
			$maxOldPersonId = (int) max(array_keys($oldCluster)) + 1;
		}

		$result = array();
		foreach ($newCluster as $newPerson => $newFaces) {
			$oldPerson = $newOldPersonMapping[$newPerson];
			if ($oldPerson === 0) {
				$result[$maxOldPersonId] = $newFaces;
				$maxOldPersonId++;
			} else {
				$result[$oldPerson] = $newFaces;
			}
		}
		return $result;
	}
}

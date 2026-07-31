<?php
/**
 * @copyright Copyright (c) 2017-2023 Matias De lellis <mati86dl@gmail.com>
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

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\Euclidean;
use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\Clusterer\ChineseWhispers;

use OCA\FaceRecognition\Service\SettingsService;

/**
 * Task that puts the faces of each user in clusters of similar faces.
 *
 * The clusters are grown, never rebuilt. Each run clusters the faces that do
 * not belong to any cluster yet, together with a few faces of each existing
 * cluster, and reads the result through those sampled faces: a group holding
 * samples of one cluster hands its new faces to that cluster, and a group
 * holding none is a cluster of its own.
 *
 * Two things follow from that. A face that already has a cluster is never
 * moved, so a cluster keeps its ID and therefore its name, and no run can
 * take a name away. And the size of the clustering is bounded by the two
 * settings that say how many faces to take and how many to sample, so a
 * cluster with fifty thousand faces costs the same as one with five.
 *
 * What it gives up is the ability to undo its own mistakes: a face left in the
 * wrong cluster stays there. Two clusters that should be one are put back
 * together when a face arrives that belongs to both, and otherwise it takes an
 * explicit `occ face:reset --clustering`.
 */
class CreateClustersTask extends FaceRecognitionBackgroundTask {
	/** @var ClusterMapper Cluster mapper*/
	private $clusterMapper;

	/** @var PersonMapper Person mapper*/
	private $personMapper;

	/** @var ImageMapper Image mapper*/
	private $imageMapper;

	/** @var FaceMapper Face mapper*/
	private $faceMapper;

	/** @var SettingsService Settings service*/
	private $settingsService;

	/**
	 * @param ClusterMapper $clusterMapper
	 * @param PersonMapper $personMapper
	 * @param ImageMapper $imageMapper
	 * @param FaceMapper $faceMapper
	 * @param SettingsService $settingsService
	 */
	public function __construct(ClusterMapper   $clusterMapper,
	                            PersonMapper    $personMapper,
	                            ImageMapper     $imageMapper,
	                            FaceMapper      $faceMapper,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->clusterMapper   = $clusterMapper;
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
		$eligable_users = $this->context->getEligibleUsers();
		foreach($eligable_users as $user) {
			yield from $this->clusterFacesOfUser($user);
		}

		return true;
	}

	/**
	 * Puts every face of the user that has no cluster yet in one.
	 *
	 * It yields after each batch, so that a run that is out of time stops here
	 * and the next one picks up the remaining faces.
	 */
	private function clusterFacesOfUser(string $userId): \Generator {
		$modelId = $this->settingsService->getCurrentFaceModel();

		if ($this->settingsService->getNeedRecreateClusters($userId)) {
			// The clustering settings changed, so what is in the database was
			// obtained with other parameters and has to be built again. Note
			// that this loses the names, exactly as `face:reset --clustering`.
			$this->logInfo('The clustering settings changed: discarding the clusters of ' . $userId);
			$this->faceMapper->unsetClustersRelationForUser($userId, $modelId);
			$this->clusterMapper->deleteUserClusters($userId);
			$this->personMapper->deleteUserPersons($userId);
			$this->settingsService->setNeedRecreateClusters(false, $userId);
		}

		$minFaceSize = $this->settingsService->getMinimumFaceSize();
		$minConfidence = $this->settingsService->getMinimumConfidence();
		$facesPerRun = $this->settingsService->getClusteringFacesPerRun();
		$samplesPerCluster = $this->settingsService->getClusteringSamplesPerCluster();

		yield from $this->clusterAloneFaces($userId, $modelId, $minFaceSize, $minConfidence, $facesPerRun);

		while (true) {
			$newFaces = $this->faceMapper->findUnassignedGroupableFaces(
				$userId, $modelId, $minFaceSize, $minConfidence, $facesPerRun);

			if (count($newFaces) === 0) {
				break;
			}

			list($sampleOf, $sizes) = $this->faceMapper->findClusterSamples(
				$userId, $modelId, $minFaceSize, $minConfidence, $samplesPerCluster);
			$decisions = $this->clusterMapper->findDecisions($userId, $modelId);

			$this->logInfo(sprintf('Clustering %d faces without a cluster, with %d faces of the %d existing clusters',
			                       count($newFaces), count($sampleOf), count($sizes)));

			$descriptors = $this->faceMapper->findDescriptorsBathed(array_merge($newFaces, array_keys($sampleOf)));
			// A face deleted while this was running leaves a hole behind.
			$descriptors = array_values(array_filter($descriptors, 'is_array'));
			$groups = $this->getNewClusters($descriptors);
			unset($descriptors);

			$plan = self::planGroups($groups, $sampleOf, $sizes, $decisions);
			unset($groups);

			$placed = $this->applyPlan($userId, $modelId, $plan);

			yield;

			if ($placed === 0) {
				// Nothing was placed, so the next round would ask for the same
				// faces and never end. It should not happen, every face of the
				// input ends up in a group.
				$this->logInfo('None of the faces could be placed in a cluster, giving up for now');
				break;
			}

			if (count($newFaces) < $facesPerRun) {
				// That was the whole backlog.
				break;
			}
		}

		$orphansDeleted = $this->clusterMapper->deleteOrphaned($userId);
		if ($orphansDeleted > 0) {
			$this->logInfo('Deleted ' . $orphansDeleted . ' clusters without faces');
		}

		$personsDeleted = $this->personMapper->deleteOrphaned($userId);
		if ($personsDeleted > 0) {
			$this->logInfo('Deleted ' . $personsDeleted . ' persons without clusters');
		}
	}

	/**
	 * Faces that are too small, too uncertain, or that the user detached, are
	 * not compared with anything: each one gets a cluster of its own.
	 */
	private function clusterAloneFaces(string $userId, int $modelId, int $minFaceSize,
	                                   float $minConfidence, int $facesPerRun): \Generator {
		while (true) {
			$faces = $this->faceMapper->findUnassignedNonGroupableFaces(
				$userId, $modelId, $minFaceSize, $minConfidence, $facesPerRun);

			if (count($faces) === 0) {
				break;
			}

			$this->logInfo('Adding ' . count($faces) . ' faces that cannot be grouped');
			foreach ($faces as $faceId) {
				$this->clusterMapper->attachFaces([$faceId], $this->clusterMapper->create($userId, $modelId));
			}

			yield;

			if (count($faces) < $facesPerRun) {
				break;
			}
		}
	}

	/**
	 * Turns the groups the clustering found into what has to happen in the
	 * database. It is separated out, and free of any dependency, because this
	 * is where every decision about the identity of a cluster is taken.
	 *
	 * A group is read through the sampled faces it contains:
	 *
	 *  - samples of a single cluster: the new faces of the group join it;
	 *  - no samples at all: the new faces of the group become a new cluster;
	 *  - samples of several clusters: those clusters are the same appearance of
	 *    the same person, split in two by the order in which the faces
	 *    arrived, so the biggest one absorbs the others. Two ages or two poses
	 *    of one person never land in the same group, since they are farther
	 *    apart than the threshold, so this cannot collapse them.
	 *
	 * A cluster the user assigned to a person, or hid, is never absorbed,
	 * because that is a decision of theirs and not of the algorithm. It can
	 * still absorb the ones nobody assigned, which is how a person reaches the
	 * faces of a fragment.
	 *
	 * @param array $groups Face IDs of each group found, as returned by getNewClusters()
	 * @param array $sampleOf Cluster each sampled face belongs to, [faceId => clusterId]
	 * @param array $sizes Number of faces of each cluster, [clusterId => size]
	 * @param array $decisions Person and visibility of each cluster,
	 *  [clusterId => ['person' => int|null, 'is_visible' => bool]]
	 *
	 * @return array ['attach' => [clusterId => faceIds], 'create' => [faceIds, ...],
	 *  'absorb' => [winnerId => loserIds]]
	 */
	public static function planGroups(array $groups, array $sampleOf, array $sizes, array $decisions): array {
		$attach = [];
		$create = [];
		$absorb = [];

		// The samples of one cluster can end up in different groups, so a
		// cluster absorbed by one group can still be named by another one.
		$absorbedBy = [];
		$survivorOf = function (int $clusterId) use (&$absorbedBy): int {
			while (isset($absorbedBy[$clusterId])) {
				$clusterId = $absorbedBy[$clusterId];
			}
			return $clusterId;
		};

		$assigned = function (int $clusterId) use ($decisions): bool {
			return !is_null($decisions[$clusterId]['person'] ?? null);
		};
		$visible = function (int $clusterId) use ($decisions): bool {
			return $decisions[$clusterId]['is_visible'] ?? true;
		};

		foreach ($groups as $faceIds) {
			$owners = [];
			$newFaces = [];
			foreach ($faceIds as $faceId) {
				if (isset($sampleOf[$faceId])) {
					$owners[$survivorOf($sampleOf[$faceId])] = true;
				} else {
					$newFaces[] = $faceId;
				}
			}
			$owners = array_keys($owners);

			if (empty($owners)) {
				if (!empty($newFaces)) {
					$create[] = $newFaces;
				}
				continue;
			}

			if (count($owners) > 1) {
				// The cluster that keeps its identity: the one of a person, then
				// the visible one, then the biggest, and the oldest on a tie.
				usort($owners, function (int $a, int $b) use ($assigned, $visible, $sizes): int {
					if ($assigned($a) !== $assigned($b)) return $assigned($a) ? -1 : 1;
					if ($visible($a) !== $visible($b)) return $visible($a) ? -1 : 1;
					$sizeA = $sizes[$a] ?? 0;
					$sizeB = $sizes[$b] ?? 0;
					if ($sizeA !== $sizeB) return $sizeB <=> $sizeA;
					return $a <=> $b;
				});

				$winner = $owners[0];
				foreach (array_slice($owners, 1) as $loser) {
					if ($assigned($loser) || !$visible($loser)) {
						continue;
					}
					$absorb[$winner][] = $loser;
					$absorbedBy[$loser] = $winner;
					$sizes[$winner] = ($sizes[$winner] ?? 0) + ($sizes[$loser] ?? 0);
					unset($sizes[$loser]);

					// The faces that were going to that cluster go to this one.
					if (isset($attach[$loser])) {
						$attach[$winner] = array_merge($attach[$winner] ?? [], $attach[$loser]);
						unset($attach[$loser]);
					}
				}
				$owners = [$winner];
			}

			if (!empty($newFaces)) {
				$owner = $owners[0];
				$attach[$owner] = array_merge($attach[$owner] ?? [], $newFaces);
			}
		}

		return ['attach' => $attach, 'create' => $create, 'absorb' => $absorb];
	}

	/**
	 * @return int Faces that were given a cluster
	 */
	private function applyPlan(string $userId, int $modelId, array $plan): int {
		$absorbed = 0;
		foreach ($plan['absorb'] as $winner => $losers) {
			$this->clusterMapper->absorbClusters((int) $winner, $losers);
			$absorbed += count($losers);
		}

		$attached = 0;
		foreach ($plan['attach'] as $clusterId => $faceIds) {
			$this->clusterMapper->attachFaces($faceIds, (int) $clusterId);
			$attached += count($faceIds);
		}

		$created = 0;
		foreach ($plan['create'] as $faceIds) {
			$this->clusterMapper->attachFaces($faceIds, $this->clusterMapper->create($userId, $modelId));
			$created += count($faceIds);
		}

		$this->logInfo(sprintf('%d faces joined an existing cluster, %d new clusters, %d clusters merged',
		                       $attached, count($plan['create']), $absorbed));

		return $attached + $created;
	}

	/**
	 * Groups the given faces by similarity with chinese whispers.
	 *
	 * @param array $faces Faces to group, each one with its 'id' and its 'descriptor'
	 *
	 * @return array Face IDs of each group found
	 */
	private function getNewClusters(array $faces): array {
		// Clustering parameters
		$sensitivity = $this->settingsService->getSensitivity();

		if (Requirements::pdlibLoaded()) {
			// Create edges (neighbors) for Chinese Whispers
			$edges = array();
			$faces_count = count($faces);
			for ($i = 0; $i < $faces_count; $i++) {
				$face1 = $faces[$i];
				for ($j = $i; $j < $faces_count; $j++) {
					$face2 = $faces[$j];
					$distance = dlib_vector_length($face1['descriptor'], $face2['descriptor']);
					if ($distance < $sensitivity) {
						$edges[] = array($i, $j);
					}
				}
			}

			// Given the edges get the list of labels (found clusters) for each face.
			$newChineseClustersByIndex = dlib_chinese_whispers($edges);
		} else {
			// Create edges (neighbors) for Chinese Whispers
			$edges = array();
			$faces_count = count($faces);

			for ($i = 0; $i < $faces_count; $i++) {
				$face1 = $faces[$i];
				for ($j = $i; $j < $faces_count; $j++) {
					$face2 = $faces[$j];
					$distance = Euclidean::distance($face1['descriptor'], $face2['descriptor']);
					if ($distance < $sensitivity) {
						$edges[] = array($i, $j);
					}
				}
			}

			// The clustering algorithm actually expects ordered lists.
			$oedges = [];
			ChineseWhispers::convert_unordered_to_ordered($edges, $oedges);
			usort($oedges, function($a, $b) {
				if ($a[0] === $b[0]) return $a[1] - $b[1];
				return $a[0] - $b[0];
			});

			// Given the edges get the list of labels (found clusters) for each face.
			$newChineseClustersByIndex = [];
			ChineseWhispers::predict($oedges, $newChineseClustersByIndex);
		}

		$newClusters = array();
		for ($i = 0, $c = count($newChineseClustersByIndex); $i < $c; $i++) {
			if (!isset($newClusters[$newChineseClustersByIndex[$i]])) {
				$newClusters[$newChineseClustersByIndex[$i]] = array();
			}
			$newClusters[$newChineseClustersByIndex[$i]][] = $faces[$i]['id'];
		}
		return $newClusters;
	}
}

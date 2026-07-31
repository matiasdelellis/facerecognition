<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Service;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\Euclidean;
use OCA\FaceRecognition\Helper\Requirements;

/**
 * Finds which other clusters could be the same person as a given one.
 *
 * One person is several clusters: the same face at another age, from another
 * angle, or with the beard shaved lands far enough apart that the clustering
 * will not join them, and it should not, since joining on that little evidence
 * is how two people end up in one cluster. What can be done is to propose the
 * candidates and let the user decide once for the whole cluster.
 *
 * The candidates are looked for when they are asked for, and nothing is stored:
 * the distances are between a few faces of each cluster, which is cheap enough
 * to do on the spot, and there is nothing to keep up to date afterwards.
 */
class ClusterLinkService {

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ClusterMapper */
	private $clusterMapper;

	/** @var PersonMapper */
	private $personMapper;

	/** @var SettingsService */
	private $settingsService;

	/** Clusters whose faces are loaded at a time, to bound the memory. */
	const CLUSTERS_PER_CHUNK = 250;

	public function __construct(ClusterMapper   $clusterMapper,
	                            FaceMapper      $faceMapper,
	                            PersonMapper    $personMapper,
	                            SettingsService $settingsService)
	{
		$this->clusterMapper   = $clusterMapper;
		$this->faceMapper      = $faceMapper;
		$this->personMapper    = $personMapper;
		$this->settingsService = $settingsService;
	}

	/**
	 * Clusters that could be the same person as $clusterId, closest first.
	 *
	 * @param string $userId Owner of the clusters
	 * @param int $clusterId Cluster to find candidates for
	 * @param int $limit How many candidates at most
	 *
	 * @return array [['id' => int, 'name' => string|null, 'distance' => float], ...]
	 */
	public function findCandidates(string $userId, int $clusterId, int $limit = 8): array {
		$modelId = $this->settingsService->getCurrentFaceModel();
		$minFaceSize = $this->settingsService->getMinimumFaceSize();
		$minConfidence = $this->settingsService->getMinimumConfidence();
		$samples = $this->settingsService->getLinkSuggestionSamples();
		$sensitivity = $this->settingsService->getLinkSuggestionSensitivity();

		list($sampleOf, $sizes) = $this->faceMapper->findClusterSamples(
			$userId, $modelId, $minFaceSize, $minConfidence, $samples);

		$ownFaces = array_keys($sampleOf, $clusterId, true);
		if (empty($ownFaces)) {
			// Nothing to compare with: every face of it was left out of the
			// grouping in the first place.
			return [];
		}

		$own = $this->descriptorsOf($ownFaces);
		if (empty($own)) {
			return [];
		}

		// Faces of the other clusters, in chunks, keeping only the distance of
		// the closest pair of each one.
		$candidates = [];
		$faceIdsByCluster = [];
		foreach ($sampleOf as $faceId => $cluster) {
			if ($cluster === $clusterId) {
				continue;
			}
			$faceIdsByCluster[$cluster][] = $faceId;
		}

		foreach (array_chunk($faceIdsByCluster, self::CLUSTERS_PER_CHUNK, true) as $chunk) {
			$faceIds = array_merge(...array_values($chunk));
			$ownerOf = [];
			foreach ($chunk as $cluster => $ids) {
				foreach ($ids as $id) {
					$ownerOf[$id] = $cluster;
				}
			}

			foreach ($this->descriptorsOf($faceIds) as $faceId => $descriptor) {
				$cluster = $ownerOf[$faceId];
				$distance = $this->closestDistance($descriptor, $own);
				if ($distance >= $sensitivity) {
					continue;
				}
				if (!isset($candidates[$cluster]) || $distance < $candidates[$cluster]) {
					$candidates[$cluster] = $distance;
				}
			}
		}

		if (empty($candidates)) {
			return [];
		}

		asort($candidates);

		// A candidate already known to be the same person has nothing to
		// propose any more.
		$decisions = $this->clusterMapper->findDecisions($userId, $modelId);
		$ownPerson = $decisions[$clusterId]['person'] ?? null;

		// Ask about the images only for the few that are going to be answered,
		// and drop the ones that cannot be the same person.
		$shortlist = array_slice($candidates, 0, $limit * 3, true);
		$images = $this->faceMapper->findClustersImages(array_merge([$clusterId], array_keys($shortlist)));
		$ownImages = $images[$clusterId] ?? [];

		$result = [];
		foreach ($shortlist as $cluster => $distance) {
			$person = $decisions[$cluster]['person'] ?? null;
			if (!is_null($ownPerson) && $person === $ownPerson) {
				continue;
			}
			if (!empty(array_intersect_key($ownImages, $images[$cluster] ?? []))) {
				// Both have a face of the same image, so they are two people.
				continue;
			}

			$result[] = [
				'id' => $cluster,
				'person' => $person,
				'name' => is_null($person) ? null : $this->nameOf($userId, $person),
				'count' => $sizes[$cluster] ?? 0,
				'distance' => round($distance, 4),
			];

			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}

	/**
	 * @param int[] $faceIds
	 *
	 * @return array [faceId => descriptor]
	 */
	private function descriptorsOf(array $faceIds): array {
		$descriptors = [];
		foreach ($this->faceMapper->findDescriptorsBathed($faceIds) as $face) {
			if (!is_array($face)) {
				// The face was deleted in the meantime.
				continue;
			}
			$descriptors[(int) $face['id']] = $face['descriptor'];
		}

		return $descriptors;
	}

	/**
	 * Distance of the closest pair between one descriptor and a set of them.
	 */
	private function closestDistance(array $descriptor, array $others): float {
		$closest = INF;
		$pdlib = Requirements::pdlibLoaded();

		foreach ($others as $other) {
			$distance = $pdlib
				? dlib_vector_length($descriptor, $other)
				: Euclidean::distance($descriptor, $other);
			if ($distance < $closest) {
				$closest = $distance;
			}
		}

		return $closest;
	}
	/**
	 * Name of a person, or null when it is gone.
	 */
	private function nameOf(string $userId, int $personId): ?string {
		try {
			return $this->personMapper->find($userId, $personId)->getName();
		} catch (\Exception $e) {
			return null;
		}
	}

}

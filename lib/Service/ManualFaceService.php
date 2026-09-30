<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

use OCA\FaceRecognition\Db\Cluster;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\FaceRect;
use OCA\FaceRecognition\Helper\ManualFaceDetector;

/**
 * What happens to single faces beyond naming them: a marking that turned out
 * to be a face already on the photo is merged into it, and the user deletes
 * or ignores faces.
 *
 * An ignored face is put in a hidden cluster of its own, which is how Face
 * Recognition already ignores a whole person (ClusterMapper::setVisibility):
 * hidden clusters are left out of the lists of people and never merged by the
 * clustering, and an ignored face is not groupable, so it is never a sample
 * that other faces could join.
 *
 * Each change is written in one transaction, so a failure leaves the faces as
 * they were.
 */
class ManualFaceService {

	/** @var IDBConnection */
	private $db;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var ClusterMapper */
	private $clusterMapper;

	/** @var PersonMapper */
	private $personMapper;

	public function __construct(IDBConnection $db,
	                            FaceMapper    $faceMapper,
	                            ClusterMapper $clusterMapper,
	                            PersonMapper  $personMapper)
	{
		$this->db            = $db;
		$this->faceMapper    = $faceMapper;
		$this->clusterMapper = $clusterMapper;
		$this->personMapper  = $personMapper;
	}

	/**
	 * The face of $faces that takes the same place as $box, the way two finds
	 * of the same face do, or null. Of several, the one that overlaps most.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $box
	 * @param Face[] $faces
	 */
	public static function sameFace(array $box, array $faces): ?Face {
		$edges = ManualFaceDetector::edges($box);
		$same = null;
		$sameOverlap = 0.0;
		foreach ($faces as $face) {
			$overlap = FaceRect::overlapPercent($edges, ManualFaceDetector::edges([
				'x' => (int) $face->getX(),
				'y' => (int) $face->getY(),
				'width' => (int) $face->getWidth(),
				'height' => (int) $face->getHeight(),
			]));
			if ($overlap >= FaceRect::SAME_FACE_MIN_OVERLAP && $overlap > $sameOverlap) {
				$same = $face;
				$sameOverlap = $overlap;
			}
		}
		return $same;
	}

	/**
	 * Whether the given cluster is one the user ignored.
	 */
	public function isIgnored(string $userId, ?int $clusterId): bool {
		$cluster = $this->clusterOf($userId, $clusterId);
		return !is_null($cluster) && !$cluster->getIsVisible();
	}

	/**
	 * Merges a marking into the face it turned out to be, which was on the
	 * photo already: the face stays and the marking goes, so that the user
	 * sees one box and the clustering compares one face.
	 *
	 * A name the marking was saved with goes to the face, as a correction of
	 * that one face: it is put in the cluster the marking was given, and
	 * leaves the one it was in. A face the user had ignored is not ignored
	 * any more: marking it says the user is interested in it after all.
	 *
	 * @return bool whether the face took the name of the marking
	 */
	public function mergeMarking(string $userId, int $markingId, ?int $markingClusterId, Face $face): bool {
		$markingCluster = $this->clusterOf($userId, $markingClusterId);
		$faceCluster = $this->clusterOf($userId, $face->getCluster());

		$markingPerson = is_null($markingCluster) ? null : $markingCluster->getPerson();
		$facePerson = is_null($faceCluster) ? null : $faceCluster->getPerson();
		$faceIgnored = !is_null($faceCluster) && !$faceCluster->getIsVisible();
		$takesName = !is_null($markingPerson) && $markingPerson !== $facePerson;

		$this->db->beginTransaction();
		try {
			if ($takesName) {
				$this->faceMapper->placeFace($face->getId(), $markingClusterId, $faceIgnored || (bool) $face->getIsGroupable());
			} elseif ($faceIgnored) {
				$this->faceMapper->placeFace($face->getId(), null, true);
			}
			$this->faceMapper->deleteFaces([$markingId]);
			$this->removeEmptyClusters($userId, [$markingClusterId, $face->getCluster()]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $takesName;
	}

	/**
	 * Merges the markings of the user that turned out to be a face already
	 * on the photo into that face, as mergeMarking() does when the search of
	 * a marking finds it. This catches the markings that were searched before
	 * the search merged them.
	 *
	 * A marking goes into a face of the analysis, or into an older marking of
	 * the same face. A marking the user ignored is left alone.
	 *
	 * @return int how many markings were merged
	 */
	public function mergeFoundMarkings(string $userId, int $modelId): int {
		$images = [];
		foreach ($this->faceMapper->findFoundMarkings($userId, $modelId) as $row) {
			$images[(int) $row['image']] = true;
		}

		$merged = 0;
		foreach (array_keys($images) as $imageId) {
			// A merge moves faces between clusters, so the faces of the photo
			// are read again after each one; every merge removes a face, so
			// this ends.
			while ($this->mergeOneFoundMarking($userId, $imageId)) {
				$merged++;
			}
		}

		return $merged;
	}

	/**
	 * Merges the newest marking of the photo that turned out to be a face
	 * already there, if there is one.
	 *
	 * @return bool whether a marking was merged
	 */
	private function mergeOneFoundMarking(string $userId, int $imageId): bool {
		$faces = $this->faceMapper->findComparableFacesOfImage($imageId);
		$markings = array_filter($faces, function (Face $face): bool {
			return $face->getManualState() === Face::MANUAL_STATE_FOUND;
		});
		// Newest first, so a marking goes into an older one of the same face.
		usort($markings, function (Face $a, Face $b): int {
			return (int) $b->getId() <=> (int) $a->getId();
		});
		$ignoredClusters = $this->clusterMapper->findHiddenIds($userId, $this->clustersOf($markings));

		foreach ($markings as $marking) {
			$markingId = (int) $marking->getId();
			$markingCluster = $marking->getCluster();
			if (!is_null($markingCluster) && in_array((int) $markingCluster, $ignoredClusters, true)) {
				continue;
			}

			$candidates = array_filter($faces, function (Face $face) use ($markingId): bool {
				$id = (int) $face->getId();
				// A marking only goes into a face of the analysis, or into an
				// older marking.
				return $id !== $markingId
					&& ($face->getManualState() !== Face::MANUAL_STATE_FOUND || $id < $markingId);
			});
			$same = self::sameFace([
				'x' => (int) $marking->getX(),
				'y' => (int) $marking->getY(),
				'width' => (int) $marking->getWidth(),
				'height' => (int) $marking->getHeight(),
			], $candidates);
			if (!is_null($same)) {
				$this->mergeMarking($userId, $markingId, is_null($markingCluster) ? null : (int) $markingCluster, $same);
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes faces. Which ones may be deleted is up to the caller.
	 *
	 * @param Face[] $faces
	 */
	public function delete(string $userId, array $faces): void {
		if (empty($faces)) {
			return;
		}

		$this->db->beginTransaction();
		try {
			$this->faceMapper->deleteFaces(array_map(function (Face $face): int {
				return (int) $face->getId();
			}, $faces));
			$this->removeEmptyClusters($userId, array_map(function (Face $face): ?int {
				return $face->getCluster();
			}, $faces));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Ignores faces: each goes into a hidden cluster of its own and out of
	 * the clustering, and leaves the cluster it was in, with its person. A
	 * face that is ignored already is left as it is.
	 *
	 * @param Face[] $faces
	 *
	 * @return int[] the faces that were ignored now
	 */
	public function ignore(string $userId, int $modelId, array $faces): array {
		$ignoredClusters = $this->clusterMapper->findHiddenIds($userId, $this->clustersOf($faces));

		$ignored = [];
		$this->db->beginTransaction();
		try {
			foreach ($faces as $face) {
				if (!is_null($face->getCluster()) && in_array((int) $face->getCluster(), $ignoredClusters, true)) {
					continue;
				}
				$hidden = $this->clusterMapper->createHidden($userId, $modelId);
				$this->faceMapper->placeFace($face->getId(), $hidden, false);
				$ignored[] = (int) $face->getId();
			}
			$this->removeEmptyClusters($userId, $this->clustersOf($faces));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $ignored;
	}

	/**
	 * Stops ignoring faces: each leaves its hidden cluster and goes back to
	 * the clustering, which places it again. A marking whose search found no
	 * face stays out of the clustering, since there is nothing to compare.
	 * A face that is not ignored is left as it is.
	 *
	 * @param Face[] $faces
	 *
	 * @return int[] the faces that are not ignored any more
	 */
	public function unignore(string $userId, array $faces): array {
		$ignoredClusters = $this->clusterMapper->findHiddenIds($userId, $this->clustersOf($faces));

		$unignored = [];
		$this->db->beginTransaction();
		try {
			foreach ($faces as $face) {
				if (is_null($face->getCluster()) || !in_array((int) $face->getCluster(), $ignoredClusters, true)) {
					continue;
				}
				$this->faceMapper->placeFace($face->getId(), null, $face->getManualState() !== Face::MANUAL_STATE_NO_FACE);
				$unignored[] = (int) $face->getId();
			}
			$this->removeEmptyClusters($userId, $this->clustersOf($faces));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $unignored;
	}

	/**
	 * @param Face[] $faces
	 *
	 * @return int[]
	 */
	private function clustersOf(array $faces): array {
		$clusters = [];
		foreach ($faces as $face) {
			if (!is_null($face->getCluster())) {
				$clusters[] = (int) $face->getCluster();
			}
		}
		return $clusters;
	}

	/**
	 * Deletes the given clusters that have no faces left, and the persons
	 * nobody points at any more.
	 *
	 * @param array<int, int|null> $clusterIds
	 */
	private function removeEmptyClusters(string $userId, array $clusterIds): void {
		foreach (array_unique(array_filter($clusterIds, function ($id): bool {
			return !is_null($id);
		})) as $clusterId) {
			$this->clusterMapper->removeIfEmpty((int) $clusterId);
		}
		$this->personMapper->deleteOrphaned($userId);
	}

	private function clusterOf(string $userId, ?int $clusterId): ?Cluster {
		if (is_null($clusterId)) {
			return null;
		}
		try {
			return $this->clusterMapper->find($userId, $clusterId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}

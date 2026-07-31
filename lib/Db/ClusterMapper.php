<?php
/**
 * @copyright Copyright (c) 2018-2026, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018-2019, Branko Kokanovic <branko@kokanovic.org>
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
namespace OCA\FaceRecognition\Db;

use OC\DB\QueryBuilder\Literal;

use OCP\IDBConnection;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The clusters of faces. A cluster belongs to a person once the user says who it
 * is, and a person has as many clusters as ways their face was found.
 */
class ClusterMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'facerecog_clusters', '\OCA\FaceRecognition\Db\Cluster');
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $clusterId ID of the cluster
	 */
	public function find(string $userId, int $clusterId): Cluster {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'model', 'person', 'is_visible')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Cluster[]
	 */
	public function findAll(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'model', 'person', 'is_visible')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)));

		return $this->findEntities($qb);
	}

	/**
	 * Clusters the user has not said who they are yet.
	 *
	 * @return Cluster[]
	 */
	public function findUnassigned(string $userId, int $modelId): array {
		return $this->findByVisibility($userId, $modelId, true);
	}

	/**
	 * Clusters the user chose to ignore.
	 *
	 * @return Cluster[]
	 */
	public function findIgnored(string $userId, int $modelId): array {
		return $this->findByVisibility($userId, $modelId, false);
	}

	/**
	 * @return Cluster[]
	 */
	private function findByVisibility(string $userId, int $modelId, bool $visible): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'model', 'person', 'is_visible')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('is_visible', $qb->createNamedParameter($visible, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('person'));

		return $this->findEntities($qb);
	}

	/**
	 * The clusters of one person, which are the different looks the user said
	 * belong to them.
	 *
	 * @return Cluster[]
	 */
	public function findByPerson(string $userId, int $modelId, int $personId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'model', 'person', 'is_visible')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('person', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Person and visibility of every cluster of the user, which is what says
	 * which cluster survives a merge and which ones must not be merged at all.
	 *
	 * @return array [clusterId => ['person' => int|null, 'is_visible' => bool]]
	 */
	public function findDecisions(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'person', 'is_visible')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)));

		$result = $qb->executeQuery();
		$clusters = [];
		while ($row = $result->fetch()) {
			$clusters[(int) $row['id']] = [
				'person' => is_null($row['person']) ? null : (int) $row['person'],
				'is_visible' => (bool) $row['is_visible'],
			];
		}
		$result->closeCursor();

		return $clusters;
	}

	public function countClusters(string $userId, int $modelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)));

		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function countClusterFaces(int $clusterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('facerecog_faces')
			->where($qb->expr()->eq('cluster', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Creates an empty cluster and returns its ID.
	 */
	public function create(string $userId, int $modelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'user' => $qb->createNamedParameter($userId),
				'model' => $qb->createNamedParameter($modelId, IQueryBuilder::PARAM_INT)])
			->executeStatement();

		return (int) $qb->getLastInsertId();
	}

	/**
	 * Puts the given faces in the given cluster, in as few statements as
	 * possible: a face is only ever added to a cluster, so there is no need to
	 * touch the faces that are already there.
	 *
	 * @param int[] $faceIds IDs of the faces
	 *
	 * @return void
	 */
	public function attachFaces(array $faceIds, int $clusterId): void {
		if (empty($faceIds)) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('facerecog_faces')
			->set('cluster', $qb->createNamedParameter($clusterId))
			->where($qb->expr()->in('id', $qb->createParameter('face_ids')));

		foreach (array_chunk($faceIds, 1000) as $chunk) {
			$qb->setParameter('face_ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$qb->executeStatement();
		}
	}

	/**
	 * Moves every face of the given clusters into $winnerId and deletes them.
	 *
	 * This is the only operation that changes the cluster of a face that
	 * already had one, and it is deliberately one sided: the surviving cluster
	 * keeps its ID, and with it its person and its visibility.
	 *
	 * @param int[] $loserIds Clusters that are absorbed
	 *
	 * @return void
	 */
	public function absorbClusters(int $winnerId, array $loserIds): void {
		$loserIds = array_values(array_diff($loserIds, [$winnerId]));
		if (empty($loserIds)) {
			return;
		}

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('facerecog_faces')
				->set('cluster', $qb->createNamedParameter($winnerId))
				->where($qb->expr()->in('cluster', $qb->createParameter('cluster_ids')));

			$delete = $this->db->getQueryBuilder();
			$delete->delete($this->getTableName())
				->where($delete->expr()->in('id', $delete->createParameter('cluster_ids')));

			foreach (array_chunk($loserIds, 1000) as $chunk) {
				$qb->setParameter('cluster_ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
				$qb->executeStatement();
				$delete->setParameter('cluster_ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
				$delete->executeStatement();
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Says which person a cluster is one of the looks of, or that it is unknown
	 * again when null.
	 *
	 * @param int|null $personId
	 *
	 * @return void
	 */
	public function setPerson(int $clusterId, $personId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('person', is_null($personId)
				? $qb->createNamedParameter(null)
				: $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)))
			->executeStatement();
	}

	/**
	 * Puts every cluster of the person back to unknown.
	 *
	 * @return void
	 */
	public function unsetPerson(int $personId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('person', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('person', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Ignoring a cluster also forgets who it was, since the user is saying they
	 * are not interested in it.
	 *
	 * @return void
	 */
	public function setVisibility(int $clusterId, bool $visible): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_visible', $qb->createNamedParameter($visible, IQueryBuilder::PARAM_BOOL));

		if (!$visible) {
			$qb->set('person', $qb->createNamedParameter(null));
		}

		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)))
			->executeStatement();
	}

	/**
	 * Takes one face out of its cluster, because the user says it is not the
	 * same person, and marks it as not groupable so that it is not put back.
	 *
	 * @param int|null $personId Person of the cluster the face goes to
	 *
	 * @return Cluster The cluster the face ended up in
	 */
	public function detachFace(int $clusterId, int $faceId, $personId = null): Cluster {
		$qb = $this->db->getQueryBuilder();
		$qb->update('facerecog_faces')
			->set('is_groupable', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)))
			->executeStatement();

		if ($this->countClusterFaces($clusterId) === 1) {
			// It is the only face of the cluster: the cluster itself is the one
			// that belongs to that person.
			$this->setPerson($clusterId, $personId);

			return $this->findById($clusterId);
		}

		$old = $this->findById($clusterId);

		$newClusterId = $this->create($old->getUser(), $old->getModel());
		if (!is_null($personId)) {
			$this->setPerson($newClusterId, $personId);
		}
		$this->attachFaces([$faceId], $newClusterId);

		return $this->findById($newClusterId);
	}

	public function findById(int $clusterId): Cluster {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'model', 'person', 'is_visible')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)));

		return $this->findEntity($qb);
	}

	/**
	 * Deletes the cluster if it has no faces left.
	 *
	 * @return void
	 */
	public function removeIfEmpty(int $clusterId): void {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'))
			->from('facerecog_faces', 'f')
			->where($sub->expr()->eq('f.cluster', $sub->createParameter('cluster')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createParameter('cluster')))
			->andWhere('NOT EXISTS (' . $sub->getSQL() . ')')
			->setParameter('cluster', $clusterId)
			->executeStatement();
	}

	/**
	 * Deletes every cluster of the user that has no faces.
	 *
	 * @return int Clusters deleted
	 */
	public function deleteOrphaned(string $userId): int {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'))
			->from('facerecog_faces', 'f')
			->where($sub->expr()->eq('f.cluster', 'c.id'));

		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id')
			->from($this->getTableName(), 'c')
			->where($qb->expr()->eq('c.user', $qb->createNamedParameter($userId)))
			->andWhere('NOT EXISTS (' . $sub->getSQL() . ')');

		$result = $qb->executeQuery();
		$orphaned = [];
		while ($row = $result->fetch()) {
			$orphaned[] = (int) $row['id'];
		}
		$result->closeCursor();

		if (empty($orphaned)) {
			return 0;
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete($this->getTableName())
			->where($delete->expr()->in('id', $delete->createParameter('ids')));

		$deleted = 0;
		foreach (array_chunk($orphaned, 1000) as $chunk) {
			$delete->setParameter('ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$deleted += $delete->executeStatement();
		}

		return $deleted;
	}

	/**
	 * @return void
	 */
	public function deleteUserClusters(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/**
	 * @return void
	 */
	public function deleteUserModel(string $userId, int $modelId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->executeStatement();
	}
}

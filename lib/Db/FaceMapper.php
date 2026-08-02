<?php
/**
 * @copyright Copyright (c) 2017-2020, Matias De lellis <mati86dl@gmail.com>
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

class FaceMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'facerecog_faces', '\OCA\FaceRecognition\Db\Face');
	}

	public function find (int $faceId): ?Face {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'image', 'cluster', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence')
			->from($this->getTableName(), 'f')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function findDescriptorsBathed (array $faceIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'descriptor')
			->from($this->getTableName(), 'f')
			->where($qb->expr()->in('id', $qb->createParameter('face_ids')));

		$descriptors = array_fill(0, sizeof($faceIds), 0);
		$arrayindex = 0;
		foreach (array_chunk($faceIds, 1000) as $chunk) {
			$qb->setParameter('face_ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$descriptors[$arrayindex] = [
					'id' => $row['id'],
					'descriptor' => json_decode($row['descriptor'])
				];
				$arrayindex++;
			}
			$result->closeCursor();
		}

		return $descriptors;
	}

	/**
	 * Based on a given fileId, takes all faces that belong to that file
	 * and return an array with that.
	 *
	 * @param string $userId ID of the user that faces belong to
	 * @param int $modelId ID of the model that faces belgon to
	 * @param int $fileId ID of file for which to search faces.
	 *
	 * @return Face[]
	 */
	public function findFromFile(string $userId, int $modelId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'x', 'y', 'width', 'height', 'cluster', 'confidence', 'creation_time')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('i.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model_id')))
			->andWhere($qb->expr()->eq('file', $qb->createParameter('file_id')))
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId)
			->setParameter('file_id', $fileId)
			->orderBy('confidence', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Counts all the faces that belong to images of a given user, created using given model
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 * @param bool $onlyWithoutClusters True if we need to count only faces which are not in a cluster yet.
	 * If false, all faces are counted.
	 */
	public function countFaces(string $userId, int $model, bool $onlyWithoutClusters=false): int {
		$qb = $this->db->getQueryBuilder();
		$qb = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('f.id') . ')'))
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')));
		if ($onlyWithoutClusters) {
			$qb = $qb->andWhere($qb->expr()->isNull('cluster'));
		}
		$query = $qb
			->setParameter('user', $userId)
			->setParameter('model', $model);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Gets oldest created face from database, for a given user and model, that is not in any cluster yet.
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 *
	 * @return Face Oldest face, if any is found
	 * @throws DoesNotExistException If there is no faces in database without cluster for a given user and model.
	 */
	public function getOldestCreatedFaceWithoutCluster(string $userId, int $model) {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('f.id', 'f.creation_time')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->isNull('cluster'))
			->orderBy('f.creation_time', 'ASC');
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		if($row === false) {
			$cursor->closeCursor();
			throw new DoesNotExistException("No faces found and we should have at least one");
		}
		$face = $this->mapRowToEntity($row);
		$cursor->closeCursor();
		return $face;
	}

	public function getFaces(string $userId, int $model): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.cluster', 'f.x', 'f.y', 'f.width', 'f.height', 'f.confidence', 'f.descriptor', 'f.is_groupable')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $userId)
			->setParameter('model', $model);
		return $this->findEntities($qb);
	}

	public function getGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.cluster')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->gte('width', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('height', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('confidence', $qb->createParameter('min_confidence')))
			->andWhere($qb->expr()->eq('is_groupable', $qb->createParameter('is_groupable')))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', true, IQueryBuilder::PARAM_BOOL);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Faces that can be grouped and do not belong to any cluster yet, which are
	 * the ones the clustering has to place. They are returned oldest first, so
	 * that repeated runs walk the backlog in a defined order.
	 *
	 * @return int[] IDs of the faces
	 */
	public function findUnassignedGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->isNull('f.cluster'))
			->andWhere($qb->expr()->gte('width', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('height', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('confidence', $qb->createParameter('min_confidence')))
			->andWhere($qb->expr()->eq('is_groupable', $qb->createParameter('is_groupable')))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', true, IQueryBuilder::PARAM_BOOL)
			->orderBy('f.id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int) $row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Faces that cannot be grouped and do not belong to any cluster yet. Each
	 * one of these ends up in a cluster of its own.
	 *
	 * @return int[] IDs of the faces
	 */
	public function findUnassignedNonGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->isNull('f.cluster'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('width', $qb->createParameter('min_size')),
				$qb->expr()->lt('height', $qb->createParameter('min_size')),
				$qb->expr()->lt('confidence', $qb->createParameter('min_confidence')),
				$qb->expr()->eq('is_groupable', $qb->createParameter('is_groupable'))
			))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', false, IQueryBuilder::PARAM_BOOL)
			->orderBy('f.id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int) $row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * A few faces of each existing cluster, and the size of every cluster.
	 *
	 * The samples are what lets an arriving face find the cluster it belongs
	 * to without putting the whole cluster in the clustering: chinese whispers
	 * only ever looks at the neighbours of a node, so one neighbour in the
	 * sample is enough to join. The oldest faces of the cluster are taken,
	 * which is deterministic and needs no extra state.
	 *
	 * Only the faces that could be grouped are sampled. A face that is too
	 * small, too uncertain, or that the user detached, was put in a cluster
	 * without ever being compared with anything, and it must not become the
	 * reason for another face to join that cluster.
	 *
	 * Only ids are read, and only the samples are kept in memory, so this
	 * costs one query and holds clusters * $samples entries.
	 *
	 * @return array [faceId => clusterId], [clusterId => size]
	 */
	public function findClusterSamples(string $userId, int $model, int $minSize, float $minConfidence, int $samples): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.cluster')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->isNotNull('f.cluster'))
			->andWhere($qb->expr()->gte('width', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('height', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('confidence', $qb->createParameter('min_confidence')))
			->andWhere($qb->expr()->eq('is_groupable', $qb->createParameter('is_groupable')))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', true, IQueryBuilder::PARAM_BOOL)
			->orderBy('f.cluster', 'ASC')
			->addOrderBy('f.id', 'ASC');

		$result = $qb->executeQuery();
		$sampleOf = [];
		$sizes = [];
		while ($row = $result->fetch()) {
			$cluster = (int) $row['cluster'];
			$sizes[$cluster] = ($sizes[$cluster] ?? 0) + 1;
			if ($sizes[$cluster] <= $samples) {
				$sampleOf[(int) $row['id']] = $cluster;
			}
		}
		$result->closeCursor();

		return [$sampleOf, $sizes];
	}

	/**
	 * Images each of the given clusters has faces in.
	 *
	 * Two faces of one image are two people, so two clusters that share an image
	 * cannot be the same person. That is the one thing that can be said for sure
	 * without looking at a descriptor.
	 *
	 * @param int[] $clusterIds
	 *
	 * @return array [clusterId => [imageId => true]]
	 */
	public function findClustersImages(array $clusterIds): array {
		if (empty($clusterIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('cluster')
			->addSelect('image')
			->from($this->getTableName())
			->where($qb->expr()->in('cluster', $qb->createParameter('cluster_ids')));

		$images = [];
		foreach (array_chunk($clusterIds, 1000) as $chunk) {
			$qb->setParameter('cluster_ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$images[(int) $row['cluster']][(int) $row['image']] = true;
			}
			$result->closeCursor();
		}

		return $images;
	}

	public function getNonGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.cluster')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('width', $qb->createParameter('min_size')),
				$qb->expr()->lt('height', $qb->createParameter('min_size')),
				$qb->expr()->lt('confidence', $qb->createParameter('min_confidence')),
				$qb->expr()->eq('is_groupable', $qb->createParameter('is_groupable'))
			))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', false, IQueryBuilder::PARAM_BOOL);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * @param int|null $limit
	 */
	public function findFromCluster(string $userId, int $clusterId, int $model, ?int $limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.image', 'f.cluster')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cluster', $qb->createNamedParameter($clusterId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)));

		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		$faces = $this->findEntities($qb);
		return $faces;
	}

	/**
	 * @param int|null $limit
	 */
	public function findFromPerson(string $userId, string $personId, int $model, ?int $limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('f', 'facerecog_clusters' ,'c', $qb->expr()->eq('f.cluster', 'c.id'))
			->innerJoin('c', 'facerecog_persons' ,'p', $qb->expr()->eq('c.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)))
			->orderBy('i.file', 'DESC');

		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		$faces = $this->findEntities($qb);

		return $faces;
	}

	/**
	 * Finds all faces contained in one image
	 * Note that this is independent of any Model
	 *
	 * @param int $imageId Image for which to find all faces for
	 *
	 */
	public function findByImage(int $imageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'image', 'cluster', 'x', 'y', 'width', 'height', 'is_groupable')
			->from($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId)));
		$faces = $this->findEntities($qb);
		return $faces;
	}

	/**
	 * Removes all faces contained in one image.
	 * Note that this is independent of any Model
	 *
	 * @param int $imageId Image for which to delete faces for
	 *
	 * @return void
	 */
	public function removeFromImage(int $imageId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId)))
			->executeStatement();
	}

	/**
	 * Deletes all faces from that user.
	 *
	 * @param string $userId User to drop faces from table.
	 *
	 * @return void
	 */
	public function deleteUserFaces(string $userId): void {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'));
		$sub->from('facerecog_images', 'i')
			->where($sub->expr()->eq('i.id', '*PREFIX*' . $this->getTableName() .'.image'))
			->andWhere($sub->expr()->eq('i.user', $sub->createParameter('user')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where('EXISTS (' . $sub->getSQL() . ')')
			->setParameter('user', $userId)
			->executeStatement();
	}

	/**
	 * Deletes all faces from that user and model
	 *
	 * @param string $userId User to drop faces from table.
	 * @param int $modelId model to drop faces from table.
	 *
	 * @return void
	 */
	public function deleteUserModel(string $userId, $modelId): void {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'));
		$sub->from('facerecog_images', 'i')
			->where($sub->expr()->eq('i.id', '*PREFIX*' . $this->getTableName() .'.image'))
			->andWhere($sub->expr()->eq('i.user', $sub->createParameter('user')))
			->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where('EXISTS (' . $sub->getSQL() . ')')
			->setParameter('user', $userId)
			->setParameter('model', $modelId)
			->executeStatement();
	}

	/**
	 * Unset the relation between the faces and their clusters, to cluster again
	 *
	 * @param string $userId User to unset the relation for.
	 *
	 * @return void
	 */
	public function unsetClustersRelationForUser(string $userId, int $model): void {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'));
		$sub->from('facerecog_images', 'i')
			->where($sub->expr()->eq('i.id', '*PREFIX*' . $this->getTableName() .'.image'))
			->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')))
			->andWhere($sub->expr()->eq('i.user', $sub->createParameter('user')));

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("cluster", $qb->createNamedParameter(null))
			->where('EXISTS (' . $sub->getSQL() . ')')
			->setParameter('model', $model)
			->setParameter('user', $userId)
			->executeStatement();
	}

	/**
	 * Insert one face to database.
	 * Note: only reason we are not using (idiomatic) QBMapper method is
	 * because "QueryBuilder::PARAM_DATE" cannot be set there
	 *
	 * @param Face $face Face to insert
	 * @param IDBConnection $db Existing connection, if we need to reuse it. Null if we commit immediatelly.
	 *
	 * @return Face
	 */
	public function insertFace(Face $face, ?IDBConnection $db = null): Face {
		if ($db !== null) {
			$qb = $db->getQueryBuilder();
		} else {
			$qb = $this->db->getQueryBuilder();
		}

		$qb->insert($this->getTableName())
			->values([
				'image' => $qb->createNamedParameter($face->image),
				'cluster' => $qb->createNamedParameter($face->cluster),
				'is_groupable' => $qb->createNamedParameter($face->isGroupable, IQueryBuilder::PARAM_BOOL),
				'x' => $qb->createNamedParameter($face->x),
				'y' => $qb->createNamedParameter($face->y),
				'width' => $qb->createNamedParameter($face->width),
				'height' => $qb->createNamedParameter($face->height),
				'confidence' => $qb->createNamedParameter($face->confidence),
				'landmarks' => $qb->createNamedParameter(json_encode($face->landmarks)),
				'descriptor' => $qb->createNamedParameter(json_encode($face->descriptor)),
				'creation_time' => $qb->createNamedParameter($face->creationTime, IQueryBuilder::PARAM_DATE),
			])
			->executeStatement();

		$face->setId($qb->getLastInsertId());

		return $face;
	}
}
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

	/** @var bool|null Whether manual_state and box_adjusted exist, once asked */
	private $hasManualState = null;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'facerecog_faces', '\OCA\FaceRecognition\Db\Face');
	}

	/**
	 * Whether the columns that keep the state of the faces marked by hand
	 * exist. A migration adds them, and the code that reads them can be
	 * deployed before it ran: until then the features that need them are left
	 * out, instead of taking the analysis and the clustering down with them.
	 *
	 * The answer is kept for the life of the mapper, so the database is asked
	 * once per run of the background job, or once per request.
	 */
	public function hasManualStateColumn(): bool {
		if ($this->hasManualState === null) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select('manual_state', 'box_adjusted')
					->from($this->getTableName())
					->setMaxResults(1);
				$qb->executeQuery()->closeCursor();
				$this->hasManualState = true;
			} catch (\Throwable $e) {
				$this->hasManualState = false;
			}
		}
		return $this->hasManualState;
	}

	public function find (int $faceId): ?Face {
		$columns = ['id', 'image', 'cluster', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'is_groupable', 'is_manual'];
		if ($this->hasManualStateColumn()) {
			$columns[] = 'manual_state';
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)
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
		// Whether a face takes part in the clustering is derived from these, so
		// that it cannot disagree with what the clustering really does.
		$columns = ['f.id', 'x', 'y', 'width', 'height', 'cluster', 'confidence', 'is_groupable', 'is_manual', 'creation_time'];
		if ($this->hasManualStateColumn()) {
			$columns[] = 'manual_state';
			$columns[] = 'box_adjusted';
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)
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
			// A manually added face has no descriptor until the model finds one
			// in the marked region, and there is nothing to compare it with
			// until then.
			->andWhere($qb->expr()->neq('descriptor', $qb->createNamedParameter('[]'), IQueryBuilder::PARAM_JSON))
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
	 * A marking that still waits for its descriptor is not one of them, even
	 * though it has no descriptor and may be drawn smaller than the minimum
	 * size. Taking it here would put it in a cluster of its own before the
	 * search ran, and once in a cluster it is never unassigned again, so the
	 * descriptor found afterwards would never be compared with anything. It
	 * comes here once the search is done, if it still cannot be grouped then.
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
			));
		if ($this->hasManualStateColumn()) {
			$qb->andWhere($this->notPendingMarking($qb, 'f'));
		}
		$qb->setParameter('user', $userId)
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
			// A manually added face has no descriptor until the model finds one
			// in the marked region, and there is nothing to compare it with
			// until then.
			->andWhere($qb->expr()->neq('descriptor', $qb->createNamedParameter('[]'), IQueryBuilder::PARAM_JSON))
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
	 * Copies the faces of one image to another, so that the analysis of a file
	 * can be reused for the same file of another user (a shared photo keeps its
	 * file id in every account).
	 *
	 * The faces are not inserted: the caller passes them to imageProcessed(),
	 * which replaces the faces of the image and can carry the clusters of the
	 * old ones over the new ones, as it does with the faces the model finds.
	 *
	 * Only the geometry and the descriptor are copied. The cluster is left
	 * unassigned and the face is made groupable again: the clusters, persons
	 * and detached faces are decisions of the user that made them, and must not
	 * leak to the user that reuses the analysis.
	 *
	 * @param int $fromImageId Image to copy the faces of
	 * @param int $toImageId Image to copy the faces to
	 *
	 * @return Face[] The copied faces
	 */
	public function copyFaces(int $fromImageId, int $toImageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('x', 'y', 'width', 'height', 'confidence', 'landmarks', 'descriptor')
			->from($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($fromImageId)));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$faces = [];
		foreach ($rows as $row) {
			$face = new Face();
			$face->image       = $toImageId;
			$face->cluster     = null;
			$face->isGroupable = true;
			$face->x           = (int)$row['x'];
			$face->y           = (int)$row['y'];
			$face->width       = (int)$row['width'];
			$face->height      = (int)$row['height'];
			$face->confidence  = (float)$row['confidence'];
			$face->landmarks   = json_decode($row['landmarks'], true);
			$face->descriptor  = json_decode($row['descriptor'], true);
			$face->setCreationTime(new \DateTime());
			$faces[] = $face;
		}

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

	/**
	 * Puts a face in a cluster, or in none, and says whether the clustering may
	 * group it. It is marked manual as well, so that analyzing the photo again
	 * keeps where the user put it.
	 */
	public function placeFace(int $faceId, ?int $clusterId, bool $groupable): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('cluster', is_null($clusterId)
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
			->set('is_groupable', $qb->createNamedParameter($groupable, IQueryBuilder::PARAM_BOOL))
			->set('is_manual', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Deletes the given faces. Their clusters are left to the caller, which
	 * knows which of them may have become empty.
	 *
	 * @param int[] $faceIds
	 */
	public function deleteFaces(array $faceIds): void {
		foreach (array_chunk(array_values(array_unique(array_map('intval', $faceIds))), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * The faces of an image that a find can be compared with: the ones with a
	 * descriptor. The markings still waiting for their search, and the ones it
	 * found nothing for, have none and are left out.
	 *
	 * @return Face[]
	 */
	public function findComparableFacesOfImage(int $imageId): array {
		$columns = ['id', 'image', 'cluster', 'x', 'y', 'width', 'height', 'is_groupable', 'is_manual'];
		if ($this->hasManualStateColumn()) {
			$columns[] = 'manual_state';
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)
			->from($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('descriptor', $qb->createNamedParameter('[]'), IQueryBuilder::PARAM_JSON))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The markings of a user whose search found a face, with their image, so
	 * that the ones that turned out to be a face already there can be found.
	 * The faces the search of a region created are among them.
	 *
	 * @return array<int, array<string, mixed>> rows with id, image, cluster, x, y, width, height
	 */
	public function findFoundMarkings(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.image', 'f.cluster', 'f.x', 'f.y', 'f.width', 'f.height')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('i.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('f.is_manual', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('f.manual_state', $qb->createNamedParameter(Face::MANUAL_STATE_FOUND)))
			->orderBy('f.image', 'ASC')
			->addOrderBy('f.id', 'DESC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The faces of an image that the user ignored: the ones in a hidden
	 * cluster.
	 *
	 * @return int[]
	 */
	public function findIgnoredFaceIdsOfImage(int $imageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('f.cluster', 'c.id'))
			->where($qb->expr()->eq('f.image', $qb->createNamedParameter($imageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.is_visible', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int) $row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Puts a face that is in no cluster into the given one. Only if it is still
	 * in none: the clustering may have placed it since it was looked at.
	 *
	 * @return bool whether the face was put there
	 */
	public function assignClusterIfNone(int $faceId, int $clusterId): bool {
		$qb = $this->db->getQueryBuilder();
		$changed = $qb->update($this->getTableName())
			->set('cluster', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('cluster'))
			->executeStatement();
		return $changed > 0;
	}

	/**
	 * Marks a face as one the user put there by hand, which is what keeps the
	 * analysis from replacing it: `imageProcessed()` deletes the faces the
	 * model found last time, and a manual face is not something it can find
	 * again.
	 */
	public function markFaceManual(int $faceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_manual', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)))
			->executeStatement();
	}

	/**
	 * Inserts a face the user drew on a photo. It carries no landmarks and no
	 * descriptor, because no model was involved in finding it, and it waits
	 * for ManualFaceDescriptorTask to search the marked region for one. The
	 * cluster is the one of the person the user named, or none when the face
	 * is left for the clustering to place.
	 */
	public function insertManualFace(Face $face): Face {
		$qb = $this->db->getQueryBuilder();

		$face->manualState = $face->manualState ?? Face::MANUAL_STATE_PENDING;

		$qb->insert($this->getTableName())
			->values([
				'image' => $qb->createNamedParameter($face->image),
				'cluster' => $qb->createNamedParameter($face->cluster),
				'x' => $qb->createNamedParameter($face->x),
				'y' => $qb->createNamedParameter($face->y),
				'width' => $qb->createNamedParameter($face->width),
				'height' => $qb->createNamedParameter($face->height),
				'confidence' => $qb->createNamedParameter($face->confidence),
				'landmarks' => $qb->createNamedParameter(json_encode([])),
				'descriptor' => $qb->createNamedParameter(json_encode([])),
				'is_groupable' => $qb->createNamedParameter((bool) $face->isGroupable, IQueryBuilder::PARAM_BOOL),
				'is_manual' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'manual_state' => $qb->createNamedParameter($face->manualState),
				'creation_time' => $qb->createNamedParameter($face->creationTime, IQueryBuilder::PARAM_DATE),
			])
			->executeStatement();

		$face->setId($qb->getLastInsertId());

		return $face;
	}

	/**
	 * Inserts a face that the search of a region the user marked found. Unlike
	 * a face drawn by hand it comes out of a detection, so it has everything a
	 * face of the analysis has, and never waits for anything: descriptor,
	 * landmarks, the box and the confidence the detector gave it.
	 *
	 * It is left without a cluster and groupable, for the clustering to place
	 * it, and it is manual, so the analysis does not delete it.
	 */
	public function insertRescanFace(Face $face): Face {
		$face->cluster = null;
		$face->isGroupable = true;
		$face->isManual = true;
		$face->manualState = Face::MANUAL_STATE_FOUND;
		$face->boxAdjusted = false;

		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'image' => $qb->createNamedParameter($face->image),
				'cluster' => $qb->createNamedParameter(null),
				'x' => $qb->createNamedParameter($face->x),
				'y' => $qb->createNamedParameter($face->y),
				'width' => $qb->createNamedParameter($face->width),
				'height' => $qb->createNamedParameter($face->height),
				'confidence' => $qb->createNamedParameter($face->confidence),
				'landmarks' => $qb->createNamedParameter(json_encode($face->landmarks)),
				'descriptor' => $qb->createNamedParameter(json_encode($face->descriptor)),
				'is_groupable' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'is_manual' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'manual_state' => $qb->createNamedParameter(Face::MANUAL_STATE_FOUND),
				'box_adjusted' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'creation_time' => $qb->createNamedParameter($face->creationTime, IQueryBuilder::PARAM_DATE),
			])
			->executeStatement();

		$face->setId($qb->getLastInsertId());

		return $face;
	}

	/**
	 * Markings whose search for a descriptor did not run yet. These are what
	 * ManualFaceDescriptorTask works on.
	 *
	 * They are told by their state, and neither by a missing descriptor nor by
	 * is_groupable: a marking the search already measured may have to be
	 * measured again, and a marking left non-groupable was either one the
	 * search gave up on or one that was never searched, which that column
	 * cannot tell apart.
	 *
	 * @return array<int, array<string, mixed>> rows with id, image, cluster, file, x, y, width, height
	 */
	public function findManualFacesPendingDescriptor(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.image', 'f.cluster', 'i.file', 'f.x', 'f.y', 'f.width', 'f.height')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('i.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($this->pendingMarking($qb, 'f'))
			->orderBy('f.id', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * A marking whose search for a descriptor did not run yet. The markings
	 * that ManualFaceDescriptorTask works on are the same the clustering has to
	 * leave alone, so both take the condition from here and cannot disagree.
	 *
	 * @return \OCP\DB\QueryBuilder\ICompositeExpression
	 */
	private function pendingMarking(IQueryBuilder $qb, string $alias) {
		return $qb->expr()->andX(
			$qb->expr()->eq($alias . '.is_manual', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
			$qb->expr()->eq($alias . '.manual_state', $qb->createNamedParameter(Face::MANUAL_STATE_PENDING))
		);
	}

	/**
	 * The negation of pendingMarking(), written out so that a null in either
	 * column, which is what every face that is not a marking has, counts as
	 * not pending instead of dropping the face from the result.
	 *
	 * @return \OCP\DB\QueryBuilder\ICompositeExpression
	 */
	private function notPendingMarking(IQueryBuilder $qb, string $alias) {
		return $qb->expr()->orX(
			$qb->expr()->isNull($alias . '.is_manual'),
			$qb->expr()->eq($alias . '.is_manual', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
			$qb->expr()->isNull($alias . '.manual_state'),
			$qb->expr()->neq($alias . '.manual_state', $qb->createNamedParameter(Face::MANUAL_STATE_PENDING))
		);
	}

	/**
	 * Stores the descriptor found for a manual face, together with the box it
	 * was actually computed from and the confidence the detector gave it. The
	 * box replaces the rectangle the user drew, so that what the frontend shows
	 * and what the clustering compares are the same face: the detection can
	 * land on a face sitting in the margin of the marked region, and then the
	 * two would describe different people. $boxAdjusted records that it landed
	 * somewhere else than where it was drawn.
	 *
	 * The confidence is the one of the detection, and it is held against the
	 * minimum confidence like the one of any other face: a face the detector
	 * itself finds bad to compare stays out of the clustering, even if the
	 * user marked it.
	 */
	public function setManualFaceDescriptor(int $faceId, array $descriptor, int $x, int $y, int $width, int $height, float $confidence, bool $boxAdjusted = false): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('descriptor', $qb->createNamedParameter(json_encode($descriptor)))
			->set('x', $qb->createNamedParameter($x))
			->set('y', $qb->createNamedParameter($y))
			->set('width', $qb->createNamedParameter($width))
			->set('height', $qb->createNamedParameter($height))
			->set('confidence', $qb->createNamedParameter($confidence))
			->set('manual_state', $qb->createNamedParameter(Face::MANUAL_STATE_FOUND))
			->set('box_adjusted', $qb->createNamedParameter($boxAdjusted, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)))
			->executeStatement();
	}

	/**
	 * Gives up on using a manual face for the clustering, because no face could
	 * be found in the marked region, or the region could not be read at all.
	 * It keeps the cluster, and therefore the person, the user gave it, and the
	 * box they drew; it is only left out of the comparisons, and it is not
	 * picked up as pending again.
	 */
	public function markManualFaceNotGroupable(int $faceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_groupable', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('manual_state', $qb->createNamedParameter(Face::MANUAL_STATE_NO_FACE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)))
			->executeStatement();
	}

	/**
	 * The faces of an image that the analysis must not replace: the ones the
	 * user marked, the ones the search of a region found, and the ones the user
	 * moved to another person. Only the markings carry a manual state.
	 *
	 * @return Face[]
	 */
	public function findManualFacesOfImage(int $imageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'image', 'cluster', 'x', 'y', 'width', 'height', 'confidence', 'is_groupable', 'is_manual', 'manual_state', 'box_adjusted')
			->from($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId)))
			->andWhere($qb->expr()->eq('is_manual', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Writes what the analysis found into the rows of faces the user put
	 * there, for the ones it found by itself in the same place. The row is
	 * overwritten and not replaced: it keeps its id, its cluster, and is_manual,
	 * so the person the user gave it stays, and so does the protection from
	 * being replaced. Box, landmarks, descriptor and confidence are the ones of
	 * the analysis from now on.
	 *
	 * 'confirm' records in the state of a marking that the analysis found it,
	 * and 'regroup' gives the clustering back a face that had been left out of
	 * it; which faces get either is up to the caller.
	 *
	 * All of them are written in one transaction, so a failure leaves every one
	 * of them as it was.
	 *
	 * @param array<int, array{face: Face, confirm: bool, regroup: bool}> $overwrites [faceId => overwrite]
	 */
	public function overwriteWithAnalysis(array $overwrites): void {
		if (empty($overwrites)) {
			return;
		}

		$this->db->beginTransaction();
		try {
			foreach ($overwrites as $faceId => $overwrite) {
				$found = $overwrite['face'];

				$qb = $this->db->getQueryBuilder();
				$qb->update($this->getTableName())
					->set('x', $qb->createNamedParameter($found->x))
					->set('y', $qb->createNamedParameter($found->y))
					->set('width', $qb->createNamedParameter($found->width))
					->set('height', $qb->createNamedParameter($found->height))
					->set('confidence', $qb->createNamedParameter($found->confidence))
					->set('landmarks', $qb->createNamedParameter(json_encode($found->landmarks)))
					->set('descriptor', $qb->createNamedParameter(json_encode($found->descriptor)));
				if ($overwrite['confirm']) {
					// The box is the one of the analysis from now on, so it
					// was not moved away from anything the user drew.
					$qb->set('manual_state', $qb->createNamedParameter(Face::MANUAL_STATE_CONFIRMED));
					$qb->set('box_adjusted', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL));
				}
				if ($overwrite['regroup']) {
					$qb->set('is_groupable', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL));
				}
				$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)))
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The number of faces of each of the given clusters, in one query however
	 * many clusters there are. A cluster without faces is left out.
	 *
	 * @param int[] $clusterIds
	 *
	 * @return array<int, int> [clusterId => number of faces]
	 */
	public function countFacesInClusters(array $clusterIds): array {
		$counts = [];
		$clusterIds = array_values(array_unique(array_map('intval', $clusterIds)));
		if (empty($clusterIds)) {
			return $counts;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('cluster')
			->selectAlias($qb->func()->count('id'), 'faces')
			->from($this->getTableName())
			->where($qb->expr()->in('cluster', $qb->createParameter('cluster_ids')))
			->groupBy('cluster');

		foreach (array_chunk($clusterIds, 1000) as $chunk) {
			$qb->setParameter('cluster_ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$counts[(int) $row['cluster']] = (int) $row['faces'];
			}
			$result->closeCursor();
		}

		return $counts;
	}
}

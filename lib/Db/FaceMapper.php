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

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class FaceMapper extends QBMapper
{

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'facerecog_faces', '\OCA\FaceRecognition\Db\Face');
	}

	public function find(int $faceId, string $userId): ?Face{
		$qb = $this->db->getQueryBuilder();
		$subPerson = "
			(SELECT cf_inner.cluster_id
			FROM *PREFIX*facerecog_cluster_faces cf_inner
			JOIN *PREFIX*facerecog_clusters c_inner ON c_inner.id = cf_inner.cluster_id
			WHERE cf_inner.face_id = f.id
			AND c_inner.user = " . $qb->createParameter('user') . "
			ORDER BY cf_inner.cluster_id
			LIMIT 1
			)
		";

		$subIsGroupable = "
			(SELECT cf_inner.is_groupable
			FROM *PREFIX*facerecog_cluster_faces cf_inner
			JOIN *PREFIX*facerecog_clusters c_inner ON c_inner.id = cf_inner.cluster_id
			WHERE cf_inner.face_id = f.id
			AND c_inner.user = " . $qb->createParameter('user') . "
			ORDER BY cf_inner.cluster_id
			LIMIT 1
			)
		";

		$qb->select(
				'f.id',
				$qb->createFunction($subPerson . ' AS person'),
				'f.image_id AS image',
				'f.x',
				'f.y',
				'f.width',
				'f.height',
				'f.landmarks',
				'f.descriptor',
				'f.confidence',
				'f.creation_time',
				// fallback true if no matching cluster_face found
				$qb->createFunction("COALESCE(" . $subIsGroupable . ", TRUE) AS is_groupable")
			)
			->from($this->getTableName(), 'f')
			->where($qb->expr()->eq('f.id', $qb->createParameter('faceId')))
			->setParameter('user', $userId)
			->setParameter('faceId', $faceId);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function findDescriptorsBathed(array $faceIds): array{
		$descriptors = [];
		for ($i = 0; $i < count($faceIds); $i= $i+1000)
		{
			$sliced = array_slice($faceIds, $i, 1000, true);
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'descriptor')
				->from($this->getTableName(), 'f')
				->where($qb->expr()->in('id', $qb->createParameter('face_ids')));
			$qb->setParameter('face_ids', $sliced, IQueryBuilder::PARAM_INT_ARRAY);

			$faces = $this->findEntities($qb);
			foreach ($faces as $face) {
				$descriptors[] = [
					'id' => $face->getId(),
					'descriptor' => json_decode($face->getDescriptor())
				];
			}
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
	public function findFromFile(string $userId, int $modelId, int $fileId): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'cf.cluster_id as person', 'f.image_id as image', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'creation_time', $qb->createFunction("COALESCE(cf.is_groupable, TRUE) as is_groupable"))
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id')) //needed for personID
			->leftJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('cf.cluster_id', 'c.id')) //needed for personID
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->andWhere($qb->expr()->eq('i.nc_file_id', $qb->createParameter('nc_file_id')))
			->setParameter('user_id', $userId)
			->orderBy('f.confidence', 'DESC')
			->setParameter('model_id', $modelId)
			->setParameter('nc_file_id', $fileId);

		$faces = $this->findEntities($qb);
		return $faces;
	}

	/**
	 * Counts all the faces that belong to images of a given user, created using given model
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 * @param bool $onlyWithoutPersons True if we need to count only faces which are not having person associated for it.
	 * If false, all faces are counted.
	 */
	public function countFaces(string $userId, int $model, bool $onlyWithoutPersons = false): int{
		$qb = $this->db->getQueryBuilder();
		$qb = $qb
			->select($qb->createFunction('COUNT(f.id)'))
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftjoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')));
		if ($onlyWithoutPersons) {
			$qb = $qb->andWhere($qb->expr()->isNull('cf.cluster_id'));
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
	 * Gets oldest created face from database, for a given user and model, that is not associated with a person.
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 *
	 * @return Face Oldest face, if any is found
	 * @throws DoesNotExistException If there is no faces in database without person for a given user and model.
	 */
	public function getOldestCreatedFaceWithoutPerson(string $userId, int $model): ?Face{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'cf.cluster_id as person', 'f.image_id as image', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'creation_time', $qb->createFunction("COALESCE(cf.is_groupable, 'true') as is_groupable"))
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->isNull('cluster_id'))
			->orderBy('f.creation_time', 'ASC');
		$qb->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Gets all faces that belong to images of a given user, created using given model
	 * 
	 * Used only in tests!
	 * 
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 * @return Face[]
	 */
	public function getFaces(string $userId, int $model): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'cf.cluster_id as person', 'f.image_id as image', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'creation_time', $qb->createFunction("COALESCE(cf.is_groupable, 'true') as is_groupable"))
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->leftJoin('f', 'facerecog_clusters', 'c', $qb->expr()->orX($qb->expr()->isNull('cf.face_id'), $qb->expr()->eq('f.id', 'cf.face_id')))
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('c.user'),
					$qb->expr()->eq('c.user', $qb->createParameter('user'))
				)
			)
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->groupBy('f.id')
			->setParameter('user', $userId)
			->setParameter('model', $model);
		return $this->findEntities($qb);
	}

	/**
	 * Gets all faces that belong to images of a given user, created using given model
	 * and that are groupable (size and confidence above threshold)
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 * @param int $minSize Minimum size (width and height) for face to be considered groupable
	 * @param float $minConfidence Minimum confidence for face to be considered groupable
	 *
	 * @return Face[]
	 */
	public function getGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select(
				'f.id',
				$qb->createFunction("CASE WHEN c.user = " . $qb->createParameter('user') . " THEN cf.cluster_id ELSE NULL END AS person"),
				'f.image_id as image', 
				'x', 
				'y',
				'width',
				'height', 
				'landmarks', 
				'descriptor', 
				'confidence', 
				'creation_time',
				$qb->createFunction("COALESCE(cf.is_groupable, TRUE) as is_groupable")
			)
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->leftJoin(
					'f', 
					'facerecog_clusters', 
					'c', 
					$qb->expr()->andX(
						$qb->expr()->eq('c.id', 'cf.cluster_id'),
						$qb->expr()->eq('c.user', $qb->createParameter('user'))
					)
				)
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->andWhere($qb->expr()->gte('f.width', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('f.height', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('f.confidence', $qb->createParameter('min_confidence')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('cf.is_groupable', $qb->createParameter('is_groupable')),
				$qb->expr()->isNull('cf.is_groupable')
			))
			->orderBy('f.id', 'ASC')
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', true, IQueryBuilder::PARAM_BOOL);

		return $this->findEntities($qb);
	}

	/**
	 * Gets all faces that belong to images of a given user, created using given model
	 * and that are not groupable (size or confidence below threshold)
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $model Model ID
	 * @param int $minSize Minimum size (width and height) for face to be considered groupable
	 * @param float $minConfidence Minimum confidence for face to be considered groupable
	 *
	 * @return Face[]
	 */
	public function getNonGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select(
				'f.id',
				$qb->createFunction("CASE WHEN c.user = " . $qb->createParameter('user') . " THEN cf.cluster_id ELSE NULL END AS person"),
				'f.image_id as image', 
				'x', 
				'y',
				'width',
				'height', 
				'landmarks', 
				'descriptor', 
				'confidence', 
				'creation_time',
				$qb->createFunction("COALESCE(cf.is_groupable, TRUE) as is_groupable")
			)
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->leftJoin(
					'f', 
					'facerecog_clusters', 
					'c', 
					$qb->expr()->andX(
						$qb->expr()->eq('c.id', 'cf.cluster_id'),
						$qb->expr()->eq('c.user', $qb->createParameter('user'))
					)
				)
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('f.width', $qb->createParameter('min_size')),
				$qb->expr()->lt('f.height', $qb->createParameter('min_size')),
				$qb->expr()->lt('f.confidence', $qb->createParameter('min_confidence')),
				$qb->expr()->eq('cf.is_groupable', $qb->createParameter('is_groupable'))
			))
			->orderBy('f.id', 'ASC')
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', false, IQueryBuilder::PARAM_BOOL);

		return $this->findEntities($qb);
	}

	/**
	 * Gets all faces that belong to cluster of a given user, created using given model
	 *
	 * @param string $userId User to which faces and associated images belongs to
	 * @param int $clusterId Cluster ID
	 * @param int|null $model Model ID
	 * @param int $minSize Minimum size (width and height) for face to be considered groupable
	 * @param float $minConfidence Minimum confidence for face to be considered groupable
	 *
	 * @return Face[]
	 */
	public function findFromCluster(string $userId, int $clusterId, int $model, ?int $limit = null, ?int $offset = null): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'cf.cluster_id as person', 'image_id as image', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'creation_time')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('c.id', 'cf.cluster_id'))
			->where($qb->expr()->eq('c.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cf.cluster_id', $qb->createNamedParameter($clusterId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)))
			->orderBy('f.id', 'ASC');

		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	/**
	 * @param int|null $limit
	 */
	public function findFromPerson(string $userId, string $personId, int $model, ?int $limit = null, ?int $offset = null): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'cf.cluster_id as person', 'image_id as image', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'creation_time')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('c.id', 'cf.cluster_id'))
			->innerJoin('f', 'facerecog_person_clusters', 'cp', $qb->expr()->eq('cp.cluster_id', 'c.id'))
			->innerJoin('f', 'facerecog_persons', 'p', $qb->expr()->eq('p.id', 'cp.person_id'))
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->where($qb->expr()->eq('c.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)))
			->orderBy('i.nc_file_id', 'DESC');

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
	 */
	public function findByImage(int $imageId): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'image_id as image', 'x', 'y', 'width', 'height', 'landmarks', 'descriptor', 'confidence', 'creation_time')
			->from($this->getTableName(), 'f')
			->where($qb->expr()->eq('f.image_id', $qb->createNamedParameter($imageId)));
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
	public function removeFromImage(int $imageId, ?IDBConnection $db = null): void{
		if ($db !== null) {
			$qb = $db->getQueryBuilder();
		} else {
			$qb = $this->db->getQueryBuilder();
		}

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('image_id', $qb->createNamedParameter($imageId)))
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
	public function deleteUserModel(string $userId, $modelId): void{
		$sub = $this->db->getQueryBuilder();
		$sub->select($sub->expr()->literal('1'));
		$sub->from('facerecog_images', 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
			->where($sub->expr()->eq('i.id', '*PREFIX*' . $this->getTableName() . '.image_id'))
			->andWhere($sub->expr()->eq('ui.user', $sub->createParameter('user')))
			->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where('EXISTS (' . $sub->getSQL() . ')')
			->setParameter('user', $userId)
			->setParameter('model', $modelId)
			->executeStatement();
	}

	/**
	 * Unset relation beetwen faces and persons from that user in order to reset clustering
	 *
	 * @param string $userId User to drop fo unset relation.
	 *
	 * @return void
	 */
	public function unsetPersonsRelationForUser(string $userId, int $model): void{
		$sub = $this->db->getQueryBuilder();
		$sub->select('cf.cluster_id')
			->from('facerecog_cluster_faces', 'cf')
			->innerJoin('cf', $this->getTableName(), 'f', $sub->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('cf', 'facerecog_images', 'i', $sub->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('cf', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
			->innerJoin('cf', 'facerecog_clusters', 'c', $sub->expr()->eq('cf.cluster_id', 'c.id'))
			->Where($sub->expr()->eq('ui.user', $sub->createParameter('user')))
			->andWhere($sub->expr()->eq('c.user', $sub->createParameter('user')))
			->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete('facerecog_cluster_faces')
			->where('cluster_id IN (' . $sub->getSQL() . ')')
			->setParameter('model', $model)
			->setParameter('user', $userId)
			->executeStatement();
	}

	/**
	 * Insert one face to database.
	 *
	 * @param Face $face Face to insert
	 * @param IDBConnection $db Existing connection, if we need to reuse it. Null if we commit immediatelly.
	 *
	 * @return Face
	 */
	public function insertFace(Face $face, ?IDBConnection $db = null): Face{
		if ($db === null) {
			$db = $this->db;
		}
		$qb = $db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'image_id' => $qb->createNamedParameter($face->image),
				'x' => $qb->createNamedParameter($face->x),
				'y' => $qb->createNamedParameter($face->y),
				'width' => $qb->createNamedParameter($face->width),
				'height' => $qb->createNamedParameter($face->height),
				'confidence' => $qb->createNamedParameter($face->confidence),
				'landmarks' => $qb->createNamedParameter(json_encode($face->landmarks)),
				'descriptor' => $qb->createNamedParameter(json_encode($face->descriptor)),
				'creation_time' => $qb->createNamedParameter($face->creationTime, IQueryBuilder::PARAM_DATE_MUTABLE),
			])
			->executeStatement();

		$face->setId($qb->getLastInsertId());
		if ($face->person !== null) {
			$insertPersonFaceConnection = $db->getQueryBuilder();
			$insertPersonFaceConnection->insert('facerecog_cluster_faces')
				->values([
					'face_id' => $insertPersonFaceConnection->createNamedParameter($face->id),
					'cluster_id' => $insertPersonFaceConnection->createNamedParameter($face->person)
				])
				->executeStatement();
		}
		return $face;
	}
}

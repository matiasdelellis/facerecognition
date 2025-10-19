<?php

/**
 * @copyright Copyright (c) 2018-2021, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018-2019, Branko Kokanovic <branko@kokanovic.org>
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

namespace OCA\FaceRecognition\Db;


use OCP\IDBConnection;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PersonMapper extends QBMapper
{

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'facerecog_clusters', '\OCA\FaceRecognition\Db\Person');
	}

	#[\Override]
	public function insert(Entity $entity): Entity {
		// get updated fields to save, fields have to be set using a setter to
		// be saved
		$properties = $entity->getUpdatedFields();

		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->tableName);

		// build the fields
		foreach ($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			if ($column === "name") {
				continue;
			}
			$getter = 'get' . ucfirst($property);
			$value = $entity->$getter();

			$type = $this->getParameterTypeForProperty($entity, $property);
			$qb->setValue($column, $qb->createNamedParameter($value, $type));
		}

		$qb->executeStatement();

		// When autoincrement is used id is always an int
		$entity->setId($qb->getLastInsertId());
		$id = $entity->getId();if ($column === "name");
		$this->updateClusterPersonConnection($id, $entity->getName());

		return $entity;
	}

	#[\Override]
	public function update(Entity $entity): Entity {
		// if entity wasn't changed it makes no sense to run a db query
		$properties = $entity->getUpdatedFields();
		if (count($properties) === 0)
			return $entity;
		// entity needs an id
		$id = $entity->getId();
		if ($id === null) {
			throw new \InvalidArgumentException(
				'Entity which should be updated has no id'
			);
		}

		// get updated fields to save, fields have to be set using a setter to
		// be saved
		// do not update the id field
		unset($properties['id']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName);
		$isExecutable = false;

		// build the fields
		foreach ($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);
			$value = $entity->$getter();
			if ($column === "name") {
				$this->updateClusterPersonConnection($id, $value);
				continue;
			}

			$type = $this->getParameterTypeForProperty($entity, $property);
			$qb->set($column, $qb->createNamedParameter($value, $type));
			$isExecutable = true;
		}

		$idType = $this->getParameterTypeForProperty($entity, 'id');

		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, $idType))
		);
		if ($isExecutable)
		{
			$qb->executeStatement();
		}

		return $entity;
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $clusterId ID of the person
	 *
	 * @return Person
	 */
	public function find(string $userId, int $clusterId): Person{
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'user', 'p.name', 'is_visible', 'is_valid', 'last_generation_time', 'linked_user')
			->from($this->getTableName(), 'c')
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->andX($qb->expr()->eq('pc.person_id', 'p.id'), $qb->expr()->isNotNull('pc.cluster_id')))
			->where($qb->expr()->eq('c.id', $qb->createNamedParameter($clusterId)))
			->andWhere($qb->expr()->eq('c.user', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @param string $personName name of the person to find
	 * @return Person[]
	 */
	public function findByName(string $userId, int $modelId, string $personName): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.user', 'p.name', 'c.is_visible', 'c.is_valid', 'c.last_generation_time', 'c.linked_user')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->andX($qb->expr()->eq('pc.person_id', 'p.id'), $qb->expr()->isNotNull('pc.cluster_id')))
			->Where($qb->expr()->eq('p.name', $qb->createParameter('person_name')))
			// ->andWhere($qb->expr()->eq('ui.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->groupBy('c.id', 'p.name')
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId)
			->setParameter('person_name', $personName);

		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @return Person[]
	 */
	public function findUnassigned(string $userId, int $modelId): array{
		return $this->getPersonsByFlagsWithoutName($userId, $modelId, true, true);
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @return Person[]
	 */
	public function findIgnored(string $userId, int $modelId): array{
		return $this->getPersonsByFlagsWithoutName($userId, $modelId, true, false);
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @param bool $isValid
	 * @param bool $isVisible
	 * @return Person[]
	 */
	public function getPersonsByFlagsWithoutName(string $userId, int $modelId, bool $isValid, bool $isVisible): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select(
				'c.id', 
				'c.user', 
				'c.is_visible', 
				'c.is_valid', 
				'c.last_generation_time',
				'c.linked_user')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->Where($qb->expr()->eq('c.is_valid', $qb->createParameter('is_valid')))
			->andWhere($qb->expr()->eq('c.is_visible', $qb->createParameter('is_visible')))
			->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->andWhere($qb->expr()->isNull('name'))
			->groupBy('c.id')
			->setParameter('user_id', $userId, IQueryBuilder::PARAM_STR)
			->setParameter('model_id', $modelId, IQueryBuilder::PARAM_INT)
			->setParameter('is_valid', $isValid, IQueryBuilder::PARAM_BOOL)
			->setParameter('is_visible', $isVisible, IQueryBuilder::PARAM_BOOL);
		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @return Person[]
	 */
	public function findAll(string $userId, int $modelId): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.user', 'p.name', 'c.is_visible', 'c.is_valid', 'c.last_generation_time', 'c.linked_user')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->Where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->groupBy('c.id', 'p.name')
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId);

		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId ID of the user
	 *
	 * @return Person[]
	 */
	public function findDistinctNames(string $userId, int $modelId): array{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('p.name')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->isNotNull('p.name'))
			->andwhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andwhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId);
		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId ID of the user
	 *
	 * @return Person[]
	 */
	public function findDistinctNamesSelected(string $userId, int $modelId, $faceNames): array{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('p.name')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andwhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->andwhere($qb->expr()->isNotNull('p.name'))
			->andWhere($qb->expr()->eq('p.name', $qb->createParameter('faceNames')))
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId)
			->setParameter('faceNames', $faceNames);
		return $this->findEntities($qb);
	}

	/**
	 * Search Person by name
	 *
	 * @param int|null $offset
	 * @param int|null $limit
	 */
	public function findPersonsLike(string $userId, int $modelId, string $name, ?int $offset = null, ?int $limit = null): array{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('p.name')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->like($qb->func()->lower('p.name'), $qb->createParameter('query')));

		$query = '%' . $this->db->escapeLikeParameter(strtolower($name)) . '%';
		$qb->setParameter('query', $query);

		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		$qb->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId);
		return $this->findEntities($qb);
	}

	/**
	 * Returns count of persons found for a given user.
	 *
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @return int Count of persons
	 */
	public function countPersons(string $userId, int $modelId): int{
		return count($this->findDistinctNames($userId, $modelId));
	}

	/**
	 * Returns count of clusters found for a given user.
	 *
	 * @param string $userId ID of the user
	 * @param int $modelId ID of the model
	 * @param bool $onlyInvalid True if client wants count of invalid clusters only,
	 *  false if client want count of all clusters
	 * @return int Count of clusters
	 */
	public function countClusters(string $userId, int $modelId, bool $onlyInvalid = false): int{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('c.id') . ') OVER ()'))
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->groupBy('c.id');

		if ($onlyInvalid) {
			$qb = $qb
				->andWhere($qb->expr()->eq('c.is_valid', $qb->createParameter('is_valid')))
				->setParameter('is_valid', false, IQueryBuilder::PARAM_BOOL);
		}

		$qb = $qb
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId);

		$resultStatement = $qb->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		if ($data !== false)
			return (int)$data[0];
		else
			return 0;
	}

	/**
	 * Based on a given image, takes all faces that belong to that image
	 * and invalidates all person that those faces belongs to.
	 *
	 * @param int $imageId ID of image for which to invalidate persons for
	 *
	 * @return void
	 */
	public function invalidatePersons(int $imageId, string $userId): void{
		$sub = $this->db->getQueryBuilder();
		$sub->select('c.id')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'facerecog_cluster_faces', 'cf', $sub->expr()->eq('cf.cluster_id', 'c.id'))
			->innerJoin('c', 'facerecog_faces', 'f', $sub->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('c', 'facerecog_images', 'i', $sub->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('c', 'facerecog_user_images', 'ui', $sub->expr()->eq('i.id', 'ui.image_id'))
			->Where($sub->expr()->eq('f.image_id', $sub->createParameter('image_id')))
			->andWhere($sub->expr()->eq('c.user', $sub->createParameter('user_id')));
		$sql = $sub->getSQL();

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_valid", $qb->createParameter('is_valid'))
			->where('id IN (' . $sql. ')')
			->setParameter('user_id', $userId)
			->setParameter('image_id', $imageId)
			->setParameter('is_valid', false, IQueryBuilder::PARAM_BOOL)
			->executeStatement();
	}

	/**
	 * Based on current clusters and new clusters, do database reconciliation.
	 * It tries to do that in minimal number of SQL queries. Operation is atomic.
	 *
	 * Clusters are array, where keys are ID of persons, and values are indexed arrays
	 * with values that are ID of the faces for those persons.
	 *
	 * @param string $userId ID of the user that clusters belong to
	 * @param array $currentClusters Current clusters
	 * @param array $newClusters New clusters
	 *
	 * @return void
	 */
	public function mergeClusterToDatabase(string $userId, $currentClusters, $newClusters): array{
		$this->db->beginTransaction();
		$currentDateTime = new \DateTime();

		$countOfClusters =[
			"deleted" => [],
			"added" => [],
			"modified" => []
		];
		try {
			// First remove all old faces from any user cluster (remove them from connection table)
			foreach ($currentClusters as $oldPerson => $oldFaces) {
				$this->removeAllFacesFromPerson($oldPerson);
			}

			// Add new clusters and update person if already existting
			foreach ($newClusters as $newPerson => $newFaces) {
				if (array_key_exists($newPerson, $currentClusters)) {
					// This cluster already existed, update cluster
					// Set cluster as valid now
					$qb = $this->db->getQueryBuilder();
					$qb->update($this->getTableName())
						->set("is_valid", $qb->createParameter('is_valid'))
						->where($qb->expr()->eq('id', $qb->createNamedParameter($newPerson, IQueryBuilder::PARAM_INT)))
						->setParameter('is_valid', true, IQueryBuilder::PARAM_BOOL)
						->executeStatement();
					$insertedClusterId = $newPerson;
					$countOfClusters["modified"][] = $insertedClusterId;
				} else {
					// Create new cluster and add all faces to it
					$qb = $this->db->getQueryBuilder();
					$qb->insert($this->getTableName())
						->values([
							'user' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
							'is_valid' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
							'last_generation_time' => $qb->createNamedParameter($currentDateTime, IQueryBuilder::PARAM_DATE_MUTABLE),
							'linked_user' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
						])
						->executeStatement();
					$insertedClusterId = $qb->getLastInsertId();
					$countOfClusters["added"][] = $insertedClusterId;
				}


				foreach ($newFaces as $newFace) {
					$this->attachFaceToPerson($insertedClusterId, $newFace);
				}
			}
			/*
			*  $this->db should be the same as not passed since then we use the local instance, 
			*  but I have no idea how the LifeCycle is managed so just to be safe passing thrue
			*/
			$countOfClusters["deleted"] = $this->deleteOrphaned($userId, $this->db);
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $countOfClusters;
	}

	/**
	 * Deletes all persons from that user.
	 *
	 * @param string $userId User to drop persons from a table.
	 *
	 * @return void
	 */
	public function deleteUserPersons(string $userId): void{
		//Delete Users Person
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->executeStatement();

		//All PersonFace connection should be deleted by foreign key
	}

	/**
	 * Deletes all persons from that user and model
	 *
	 * @param string $userId ID of user for drop from table
	 * @param int $modelId
	 *
	 * @return void
	 */
	public function deleteUserModel(string $userId, int $modelId): void{
		//TODO: Make it atomic
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createParameter('person')));

		$persons = $this->findAll($userId, $modelId);
		foreach ($persons as $person) {
			$qb->setParameter('person', $person->getId(), IQueryBuilder::PARAM_INT)
				->executeStatement();
		}
	}

	/**
	 * Deletes person if it is empty (have no faces associated to it)
	 *
	 * @param int $clusterId Person to check if it should be deleted
	 *
	 * @return void
	 */
	public function removeIfEmpty(int $clusterId): void{
		$sub = $this->db->getQueryBuilder();
		$sub->select('c.id');
		$sub->from($this->getTableName(), 'c')
			->leftJoin('c', 'facerecog_cluster_faces', 'cf', $sub->expr()->eq('cf.cluster_id', 'c.id'))
			->where($sub->expr()->eq('cf.cluster_id', $sub->createParameter('cluster_id')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createParameter('cluster_id')))
			->andWhere('id NOT IN (' . $sub->getSQL() . ')')
			->setParameter('cluster_id', $clusterId, IQueryBuilder::PARAM_INT)
			->executeStatement();
	}

	/**
	 * Deletes all persons that have no faces associated to them
	 *
	 * @param string $userId ID of user for which we are deleting orphaned persons
	 */
	public function deleteOrphaned(string $userId, ?IDBConnection $db = null): array{
		if ($db === null) {
			$db = $this->db;
		}

		$qb = $db->getQueryBuilder();
		$qb->select('c.id')
			->from($this->getTableName(), 'c')
			->leftJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('c.id', 'cf.cluster_id'))
			->where($qb->expr()->eq('c.user', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNull('cf.face_id'));
		$orphanedPersons = $this->findEntities($qb);

		$orphaned = [];
		foreach ($orphanedPersons as $person) {
			$qb = $db->getQueryBuilder();
			$orphaned[] = $person->getId();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('id', $qb->createNamedParameter($person->getId(), IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return $orphaned;
	}

	/*
	 * Mark the cluster as hidden or visible to user.
	 *
	 * @param int $clusterId ID of the person
	 * @param bool $visible visibility of the person
	 *
	 * @return void
	 */
	public function setVisibility(int $clusterId, bool $visible): void{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_visible', $qb->createNamedParameter($visible, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		if (!$visible) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('facerecog_person_clusters')
				->Where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	/*
	 * insert Person name if not exists
	 *
	 * @param string $personName name of the person
	 * @param IDBConnection|null $db optional dbConnection
	 *
	 * @return int id of person
	 */
	public function insertPersonIfNotExists(string $personName, ?IDBConnection $db = null): int{
		if ($db === null) {
			$db = $this->db;
		}

		$qb = $db->getQueryBuilder();
		$qb->select('id')
			->from('facerecog_persons')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($personName)));
		$result = $qb->executeQuery();

		$data = $result->fetch();
		$result->closeCursor();
		if ($data != false) {
			return $data['id'];
		}
		
		$qb = $db->getQueryBuilder();
		$qb->insert('facerecog_persons')
			->values(
				[
					'name' => $qb->createNamedParameter($personName)
				])
			->executeStatement();
		return $qb->getLastInsertId();
	}

	/*
	 * handling cluster-Person connection based on name
	 *
	 * @param int $clusterId ID of cluster
	 * @param string $personName name of the person
	 * @param IDBConnection|null $db optional dbConnection
	 *
	 * @return int id of person
	 */
	public function updateClusterPersonConnection(int $clusterId, ?string $personName, ?IDBConnection $db = null): void{
		if ($db === null) {
			$db = $this->db;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('*')
			->from('facerecog_person_clusters')
			->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
		$result = $qb->executeQuery();

		$data = $result->fetchAll();
		$result->closeCursor();
		if ($data != false) {
			if (count($data) > 1)
			{
				$msg = 'Did not expect more than one result when executing: query "' . $qb->getSQL() . '"'; 
				throw new MultipleObjectsReturnedException($msg);
			}

			$qb = $db->getQueryBuilder();
			$qb->delete('facerecog_person_clusters')
				->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
				->andWhere($qb->expr()->eq('person_id', $qb->createNamedParameter($data[0]['person_id'])))
				->executeStatement();

			//Clear leftalone perons
			$qb = $db->getQueryBuilder();
			$orphanedResult = $qb->select('p.id')
				->from('facerecog_persons', 'p')
				->leftJoin('p', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('p.id', 'pc.person_id'))
				->Where($qb->expr()->isNull('pc.person_id'))
				->executeQuery();
			$orphanedPersons = $orphanedResult->fetchAll();
			$orphanedResult->closeCursor();

			foreach ($orphanedPersons as $person) {
				$qb = $db->getQueryBuilder();
				$qb->delete('facerecog_persons')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($person['id'], IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		}
		if ($personName !== null){
			$personId = $this->insertPersonIfNotExists($personName, $db);
			$qb = $db->getQueryBuilder();
			$qb->insert('facerecog_person_clusters')
			->values(
				[
					'cluster_id' => $qb->createNamedParameter($clusterId),
					'person_id' =>  $qb->createNamedParameter($personId)
				])
				->executeStatement();
		}
	}

	public function countClusterFaces(int $clusterId): int{
		$qb = $this->db->getQueryBuilder();
		$resultStatement = $qb
			->select($qb->func()->count('*'))
			->from('facerecog_cluster_faces')
			->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
			->executeQuery();

		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Updates one face with $faceId to database to person ID $clusterId.
	 *
	 * @param int $faceId ID of the face
	 * @param int|null $oldPerson ID of the Old person if NULL new connection will be create
	 * @param int|null $clusterId ID of the NEW person if NULL connection will be deleted 
	 *
	 * @return void
	 */
	public function updateFace(int $faceId, ?int $oldCluster, ?int $clusterId, bool $isGroupable): void{
		if($oldCluster === null && $clusterId === null)
		{
			throw new \InvalidArgumentException('No clusterId was given to face Id:' . $faceId);
		} 
		if ($oldCluster === null)
		{
			$this->attachFaceToPerson($clusterId, $faceId, $isGroupable);
			return;
		}
		if ($clusterId === null)
		{
			$this->detachFace($oldCluster, $faceId);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('facerecog_cluster_faces')
			->set("cluster_id", $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
			->set("is_groupable", $qb->createNamedParameter($isGroupable, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('face_id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($oldCluster, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Remove ALL faces from person ID $clusterId.
	 *
	 * @param int $clusterId ID of the Old person if NULL new connection will be create
	 *
	 * @return void
	 */
	public function removeAllFacesFromPerson(int $clusterId): void{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('facerecog_cluster_faces')
			->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
			->executeStatement();
	}

	/**
	 * Attach one face with $faceId to person ID $clusterId.
	 *
	 * @param int $clusterId ID of the Old cluster if NULL new connection will be create
	 * @param int $faceId ID of the face
	 * @param bool $isGroupable face can be grouped
	 *
	 * @return void
	 */
	public function attachFaceToPerson( int $clusterId, int $faceId, bool $isGroupable = true): void{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('facerecog_cluster_faces')
			->values([
				'face_id' => $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT),
				'cluster_id' => $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT),
				'is_groupable' => $qb->createNamedParameter($isGroupable, IQueryBuilder::PARAM_BOOL),
			])
			->executeStatement();
	}
	
	/*
	 * Remove face from cluster
	 *
	 * @param int $clusterId ID of the person
	 * @param int $faceId ID of the FACE
	 * @param string|null $name optional name to rename them.
	 *
	 * @return Person
	 */
	public function detachFace(int $clusterId, int $faceId, ?string $name = null): Person{
		if ($this->countClusterFaces($clusterId) === 1) {
			// If cluster is an single face just rename it.
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('is_visible', $qb->createNamedParameter(true))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)))
				->executeStatement();
			$this->updateClusterPersonConnection($clusterId, $name, $this->db);
		} else {
			// If there are other faces, must create a new person for that face.
			$qb = $this->db->getQueryBuilder();
			$qb->select('user')
				->from($this->getTableName())
				->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)));
			$oldPerson = $this->findEntity($qb);

			$qb = $this->db->getQueryBuilder();
			$qb->insert($this->getTableName())
				->values([
					'user' => $qb->createNamedParameter($oldPerson->getUser(), IQueryBuilder::PARAM_STR),
					'is_valid' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
					'last_generation_time' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE_MUTABLE),
					'linked_user' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
					'is_visible' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
				])
				->executeStatement();

			$newclusterId = $qb->getLastInsertId();
			// Mark the face as non groupable.
			$this->updateFace($faceId, $clusterId, $newclusterId, false);
			$this->updateClusterPersonConnection($newclusterId, $name, $this->db);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.user', 'p.name', 'c.is_visible', 'c.is_valid', 'c.last_generation_time', 'c.linked_user')
			->from($this->getTableName(), 'c')
			->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
			->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->eq('c.id', $qb->createNamedParameter($clusterId)));
		return $this->findEntity($qb);
	}

}

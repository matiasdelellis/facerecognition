<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
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

class RelationMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'facerecog_relations', '\OCA\FaceRecognition\Db\Relation');
	}

	/**
	 * Find all relation from that user.
	 *
	 * @param string $userId User user to search
	 * @param int $modelId
	 * @return array
	 */
	public function findByUser(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.face1', 'r.face2', 'r.state')
		    ->from($this->getTableName(), 'r')
		    ->innerJoin('r', 'facerecog_faces', 'f', $qb->expr()->eq('r.face1', 'f.id'))
		    ->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image', 'i.id'))
		    ->where($qb->expr()->eq('i.user', $qb->createParameter('user_id')))
		    ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
		    ->setParameter('user_id', $userId)
		    ->setParameter('model_id', $modelId);

		return $this->findEntities($qb);
	}

	public function findFromPerson(string $userId, int $personId, int $state): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.face1', 'r.face2', 'r.state')
		   ->from($this->getTableName(), 'r')
		   ->innerJoin('r', 'facerecog_faces' ,'f', $qb->expr()->orX($qb->expr()->eq('r.face1', 'f.id'), $qb->expr()->eq('r.face2', 'f.id')))
		   ->innerJoin('f', 'facerecog_persons' ,'p', $qb->expr()->eq('f.person', 'p.id'))
		   ->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
		   ->andWhere($qb->expr()->eq('p.id', $qb->createNamedParameter($personId)))
		   ->andWhere($qb->expr()->eq('r.state', $qb->createNamedParameter($state)));

		return $this->findEntities($qb);
	}

	public function findFromPersons(int $personId1, int $personId2) {
		$sub1 = $this->db->getQueryBuilder();
		$sub1->select('f.id')
		      ->from('facerecog_faces', 'f')
		      ->where($sub1->expr()->eq('f.person', $sub1->createParameter('person1')));

		$sub2 = $this->db->getQueryBuilder();
		$sub2->select('f.id')
		      ->from('facerecog_faces', 'f')
		      ->where($sub2->expr()->eq('f.person', $sub2->createParameter('person2')));

		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.face1', 'r.face2', 'r.state')
		   ->from($this->getTableName(), 'r')
		   ->where('((r.face1 IN (' . $sub1->getSQL() . ')) AND (r.face2 IN (' . $sub2->getSQL() . ')))')
		   ->orWhere('((r.face2 IN (' . $sub1->getSQL() . ')) AND (r.face1 IN (' . $sub2->getSQL() . ')))')
		   ->setParameter('person1', $personId1)
		   ->setParameter('person2', $personId2);

		return $this->findEntities($qb);
	}

	/**
	 * Deletes all relations from that user.
	 *
	 * @param string $userId User to drop persons from a table.
	 */
	public function deleteUser(string $userId) {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'))
		     ->from('facerecog_faces', 'f')
		     ->innerJoin('f', 'facerecog_images', 'i', $sub->expr()->eq('f.image', 'i.id'))
		     ->andWhere($sub->expr()->eq('i.user', $sub->createParameter('user_id')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
		    ->where('EXISTS (' . $sub->getSQL() . ')')
		    ->setParameter('user_id', $userId)
		    ->execute();
	}

	/**
	 * Find all the relations of a user as an matrix array, which is faster to access.
	 * @param string $userId
	 * @param int $modelId
	 * return array
	 */
	public function findByUserAsMatrix(string $userId, int $modelId): array {
		$matrix = array();
		$relations = $this->findByUser($userId, $modelId);
		foreach ($relations as $relation) {
			$row = array();
			if (isset($matrix[$relation->face1])) {
				$row = $matrix[$relation->face1];
			}
			$row[$relation->face2] = $relation->state;
			$matrix[$relation->face1] = $row;
		}
		return $matrix;
	}

	public function getStateOnMatrix(int $face1, int $face2, array $matrix): int {
		if (isset($matrix[$face1])) {
			$row = $matrix[$face1];
			if (isset($row[$face2])) {
				return $matrix[$face1][$face2];
			}
		}
		if (isset($matrix[$face2])) {
			$row = $matrix[$face2];
			if (isset($row[$face1])) {
				return $matrix[$face2][$face1];
			}
		}
		return Relation::PROPOSED;
	}

	public function existsOnMatrix(int $face1, int $face2, array $matrix): bool {
		if (isset($matrix[$face1])) {
			$row = $matrix[$face1];
			if (isset($row[$face2])) {
				return true;
			}
		}
		if (isset($matrix[$face2])) {
			$row = $matrix[$face2];
			if (isset($row[$face1])) {
				return true;
			}
		}
		return false;
	}

	public function merge(string $userId, int $modelId, array $relations): int {
		$added = 0;
		$this->db->beginTransaction();
		try {
			$oldMatrix = $this->findByUserAsMatrix($userId, $modelId);
			foreach ($relations as $relation) {
				if ($this->existsOnMatrix($relation->face1, $relation->face2, $oldMatrix))
					continue;
				$this->insert($relation);
				$added++;
			}
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $added;
	}

}
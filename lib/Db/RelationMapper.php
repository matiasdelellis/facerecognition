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

	public function exists(Relation $relation): bool {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select(['id'])
			->from($this->getTableName())
			->where($qb->expr()->andX($qb->expr()->eq('face1', $qb->createParameter('face1')), $qb->expr()->eq('face2', $qb->createParameter('face2'))))
			->orWhere($qb->expr()->andX($qb->expr()->eq('face2', $qb->createParameter('face1')), $qb->expr()->eq('face1', $qb->createParameter('face2'))))
			->setParameter('face1', $relation->getFace1())
			->setParameter('face2', $relation->getFace2());

		$resultStatement = $query->execute();
		$row = $resultStatement->fetch();
		$resultStatement->closeCursor();

		return ($row !== false);
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

	public function merge(array $relations): int {
		$addedCount = 0;

		$this->db->beginTransaction();
		foreach ($relations as $relation) {
			if ($this->exists($relation))
				continue;

			$this->insert($relation);
			$addedCount++;
		}
		$this->db->commit();

		return $addedCount;
	}

}
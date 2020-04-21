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
			->where($qb->expr()->eq('face1', $qb->createParameter('face1')))
			->andWhere($qb->expr()->eq('face2', $qb->createParameter('face2')))
			->setParameter('face1', $relation->getFace1())
			->setParameter('face2', $relation->getFace2());

		$resultStatement = $query->execute();
		$row = $resultStatement->fetch();
		$resultStatement->closeCursor();

		return ($row !== false);
	}

	/*public function findFromPerson(string $userId, int $personId, int $model, int $state = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.face1', 'r.face1', 'r.state')
			->from($this->getTableName(), 'r')
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('f', 'facerecog_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('person', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)));

		if (!is_null($state)) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		$relations = $this->findEntities($qb);

		return $relations;
	}*/

}
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
 * The people the user named. Their faces are in the clusters that point here.
 */
class PersonMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'facerecog_persons', '\OCA\FaceRecognition\Db\Person');
	}

	public function find(string $userId, int $personId): Person {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'name')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * The person of that name, if there is one. Note that two people of the
	 * same user can be called the same, in which case the first one is
	 * returned: the name is how they are asked for, but not what they are.
	 */
	public function findByName(string $userId, string $name): ?Person {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user', 'name')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * The person of that name, created if it is a new one.
	 */
	public function findOrCreateByName(string $userId, string $name): Person {
		$person = $this->findByName($userId, $name);
		if (!is_null($person)) {
			return $person;
		}

		$person = new Person();
		$person->setUser($userId);
		$person->setName($name);

		return $this->insert($person);
	}

	/**
	 * Every person that has at least one cluster of the given model.
	 *
	 * @return Person[]
	 */
	public function findAll(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('p.id')
			->addSelect('p.user', 'p.name')
			->from($this->getTableName(), 'p')
			->innerJoin('p', 'facerecog_clusters', 'c', $qb->expr()->eq('c.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('c.model', $qb->createNamedParameter($modelId)))
			->orderBy('p.name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * People whose name contains the given text.
	 *
	 * @param int|null $offset
	 * @param int|null $limit
	 *
	 * @return Person[]
	 */
	public function findLike(string $userId, int $modelId, string $name, ?int $offset = null, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('p.id')
			->addSelect('p.user', 'p.name')
			->from($this->getTableName(), 'p')
			->innerJoin('p', 'facerecog_clusters', 'c', $qb->expr()->eq('c.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('c.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->like($qb->func()->lower('p.name'), $qb->createParameter('query')))
			->orderBy('p.name', 'ASC');

		$qb->setParameter('query', '%' . $this->db->escapeLikeParameter(strtolower($name)) . '%');
		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	public function countPersons(string $userId, int $modelId): int {
		return count($this->findAll($userId, $modelId));
	}

	/**
	 * @return void
	 */
	public function rename(int $personId, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($personId)))
			->executeStatement();
	}

	/**
	 * Deletes the people that no cluster points at any more.
	 *
	 * @return int People deleted
	 */
	public function deleteOrphaned(string $userId): int {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'))
			->from('facerecog_clusters', 'c')
			->where($sub->expr()->eq('c.person', 'p.id'));

		$qb = $this->db->getQueryBuilder();
		$qb->select('p.id')
			->from($this->getTableName(), 'p')
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
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
	public function deleteUserPersons(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->executeStatement();
	}
}

<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
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

use OC\DB\QueryBuilder\Literal;

use OCP\IDBConnection;
use OCP\IUser;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PersonMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition_persons', '\OCA\FaceRecognition\Db\Person');
	}


	public function find(string $userId, int $personId): Person {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('face_recognition_persons', 'p')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
		$person = $this->findEntity($qb);
		return $person;
	}

	public function findAll (string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('face_recognition_persons', 'p')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

		$person = $this->findEntities($qb);
		return $person;
	}

	/**
	 * Returns count of persons (clusters) found for a given user.
	 *
	 * @param string $userId ID of the user
	 * @param bool $onlyInvalid True if client wants count of invalid persons only,
	 *  false if client want count of all persons
	 * @return int Count of persons
	 */
	public function countPersons(string $userId, bool $onlyInvalid=false): int {
		$qb = $this->db->getQueryBuilder();
		$qb = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')));
		if ($onlyInvalid) {
			$qb = $qb
				->andWhere($qb->expr()->eq('is_valid', $qb->createParameter('is_valid')))
				->setParameter('is_valid', false, IQueryBuilder::PARAM_BOOL);
		}
		$query = $qb->setParameter('user', $userId);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Based on a given fileId, takes all person that belong to that image
	 * and return an array with that.
	 *
	 * @param string $userId ID of the user that clusters belong to
	 * @param int $fileId ID of file image for which to searh persons.
	 *
	 * @return array of persons
	 */
	public function findFromFile(string $userId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.id', 'name');
		$qb->from("face_recognition_persons", "p")
			->innerJoin('p', 'face_recognition_faces' ,'f', $qb->expr()->eq('p.id', 'f.person'))
			->innerJoin('p', 'face_recognition_images' ,'i', $qb->expr()->eq('i.id', 'f.image'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.file', $qb->createNamedParameter($fileId)));
		$persons = $this->findEntities($qb);

		return $persons;
	}

	/**
	 * Based on a given image, takes all faces that belong to that image
	 * and invalidates all person that those faces belongs to.
	 *
	 * @param int $imageId ID of image for which to invalidate persons for
	 */
	public function invalidatePersons(int $imageId) {
		$sub = $this->db->getQueryBuilder();
		$tableNameWithPrefixWithoutQuotes = trim($sub->getTableName($this->getTableName()), '`');
		$sub->select(new Literal('1'));
		$sub->from("face_recognition_images", "i")
			->innerJoin('i', 'face_recognition_faces' ,'f', $sub->expr()->eq('i.id', 'f.image'))
			->where($sub->expr()->eq($tableNameWithPrefixWithoutQuotes . '.id', 'f.person'))
			->andWhere($sub->expr()->eq('i.id', $sub->createParameter('image_id')));

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_valid", $qb->createParameter('is_valid'))
			->where('EXISTS (' . $sub->getSQL() . ')')
			->setParameter('image_id', $imageId)
			->setParameter('is_valid', false, IQueryBuilder::PARAM_BOOL)
			->execute();
	}

	/**
	 * Updates one face with $faceId to database to person ID $personId.
	 *
	 * @param int $faceId ID of the face
	 * @param int|null $personId ID of the person
	 */
	private function updateFace(int $faceId, $personId) {
		$qb = $this->db->getQueryBuilder();
		$qb->update('face_recognition_faces')
			->set("person", $qb->createNamedParameter($personId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId)))
			->execute();
	}

	/**
	 * Based on current clusters and new clusters, do database reconciliation.
	 * It tries to do that in minumal number of SQL queries. Operation is atomic.
	 *
	 * Clusters are array, where keys are ID of persons, and values are indexed arrays
	 * with values that are ID of the faces for those persons.
	 *
	 * @param string $userId ID of the user that clusters belong to
	 * @param array $currentClusters Current clusters
	 * @param array $newClusters New clusters
	 */
	public function mergeClusterToDatabase(string $userId, $currentClusters, $newClusters) {
		$this->db->beginTransaction();
		$currentDateTime = new \DateTime();

		try {
			// Delete clusters that do not exist anymore
			foreach($currentClusters as $oldPerson => $oldFaces) {
				if (array_key_exists($oldPerson, $newClusters)) {
					continue;
				}

				// OK, we bumped into cluster that existed and now it does not exist.
				// We need to remove all references to it and to delete it.
				foreach ($oldFaces as $oldFace) {
					$this->updateFace($oldFace, null);
				}

				// todo: this is not very cool. What if user had associated linked user to this. And all lost?
				$qb = $this->db->getQueryBuilder();
				// todo: for extra safety, we should probably add here additional condition, where (user=$userId)
				$qb
					->delete($this->getTableName())
					->where($qb->expr()->eq('id', $qb->createNamedParameter($oldPerson)))
					->execute();
			}

			// Modify existing clusters
			foreach($newClusters as $newPerson=>$newFaces) {
				if (!array_key_exists($newPerson, $currentClusters)) {
					// This cluster didn't exist, there is nothing to modify
					// It will be processed during cluster adding operation
					continue;
				}

				$oldFaces = $currentClusters[$newPerson];
				if ($newFaces === $oldFaces) {
					continue;
				}

				// OK, set of faces do differ. Now, we could potentially go into finer grain details
				// and add/remove each individual face, but this seems too detailed. Enough is to
				// reset all existing faces to null and to add new faces to new person. That should
				// take care of both faces that are removed from cluster, as well as for newly added
				// faces to this cluster.

				// First remove all old faces from any cluster (reset them to null)
				foreach ($oldFaces as $oldFace) {
					// Reset face to null only if it wasn't moved to other cluster!
					// (if face is just moved to other cluster, do not reset to null, as some other
					// pass for some other cluster will eventually update it to proper cluster)
					if ($this->isFaceInClusters($oldFace, $newClusters) === false) {
						$this->updateFace($oldFace, null);
					}
				}

				// Then set all new faces to belong to this cluster
				foreach ($newFaces as $newFace) {
					$this->updateFace($newFace, $newPerson);
				}
			}

			// Add new clusters
			foreach($newClusters as $newPerson=>$newFaces) {
				if (array_key_exists($newPerson, $currentClusters)) {
					// This cluster already existed, nothing to add
					// It was already processed during modify cluster operation
					continue;
				}

				// Create new cluster and add all faces to it
				$qb = $this->db->getQueryBuilder();
				$qb
					->insert($this->getTableName())
					->values([
						'user' => $qb->createNamedParameter($userId),
						'name' => $qb->createNamedParameter(sprintf("New person %d", $newPerson)),
						'is_valid' => $qb->createNamedParameter(true),
						'last_generation_time' => $qb->createNamedParameter($currentDateTime, IQueryBuilder::PARAM_DATE),
						'linked_user' => $qb->createNamedParameter(null)])
					->execute();
				$insertedPersonId = $this->db->lastInsertId($this->getTableName());
				foreach ($newFaces as $newFace) {
					$this->updateFace($newFace, $insertedPersonId);
				}
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Deletes all persons from that user.
	 *
	 * @param string $userId User to drop persons from a table.
	 */
	public function deleteUserPersons(string $userId) {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->execute();
	}

	/**
	 * Checks if face with a given ID is in any cluster.
	 *
	 * @param int $faceId ID of the face to check
	 * @param array $cluster All clusters to check into
	 *
	 * @return bool True if face is found in any cluster, false otherwise.
	 */
	private function isFaceInClusters(int $faceId, array $clusters): bool {
		foreach ($clusters as $_=>$faces) {
			if (in_array($faceId, $faces)) {
				return true;
			}
		}
		return false;
	}

}

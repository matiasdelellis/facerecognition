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

use OCP\AppFramework\Db\Mapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PersonMapper extends Mapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition_persons', '\OCA\FaceRecognition\Db\Person');
	}

	/**
	 * Returns count of persons (clusters) found for a given user.
	 *
	 * @param string $userId ID of the user
	 *
	 * @return int Count of persons
	 */
	public function countPersons(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->setParameter('user', $userId);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Based on a given image, takes all faces that belong to that image
	 * and invalidates all person that those faces belongs to.
	 *
	 * @param int $imageId ID of image for which to invalidate persons for
	 */
	public function invalidatePersons(int $imageId) {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'));
		$sub->from("face_recognition_images", "i")
			->innerJoin('i', 'face_recognition_faces' ,'f', $sub->expr()->eq('i.id', 'f.image'))
			->where($sub->expr()->eq('p.id', 'f.person'))
			->andWhere($sub->expr()->eq('i.id', $sub->createParameter('image_id')));

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName(), 'p')
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

			// Add or modify existing clusters
			foreach($newClusters as $newPerson=>$newFaces) {
				if (array_key_exists($newPerson, $currentClusters)) {
					// This cluster existed, check if faces match
					$oldFaces = $currentClusters[$newPerson];
					if ($newFaces == $oldFaces) {
						continue;
					}

					// OK, set of faces do differ. Now, we could potentially go into finer grain details
					// and add/remove each individual face, but this seems too detailed. Enough is to
					// reset all existing faces to null and to add new faces to new person. That should
					// take care of both faces that are removed from cluster, as well as for newly added
					// faces to this cluster.
					foreach ($oldFaces as $oldFace) {
						$this->updateFace($oldFace, null);
					}
					foreach ($newFaces as $newFace) {
						$this->updateFace($newFace, $newPerson);
					}
				} else {
					// This person doesn't even exist, insert it
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
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

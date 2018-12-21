<?php
namespace OCA\FaceRecognition\Db;

use OC\DB\QueryBuilder\Literal;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class FaceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition_faces', '\OCA\FaceRecognition\Db\Face');
	}

	public function find (int $faceId): Face {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'image', 'person', 'left', 'right', 'top', 'bottom', 'descriptor')
			->from('face_recognition_faces', 'f')
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($faceId)));
		$faces = $this->findEntity($qb);
		return $faces;
	}

	public function findAllFromPerson (int $personId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'image', 'person', 'left', 'right', 'top', 'bottom', 'descriptor')
			->from('face_recognition_faces', 'f')
			->where($qb->expr()->eq('person', $qb->createNamedParameter($personId)));
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
	public function countFaces(string $userId, int $model, bool $onlyWithoutPersons=false): int {
		$qb = $this->db->getQueryBuilder();
		$qb = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('f.id') . ')'))
			->from('face_recognition_faces', 'f')
			->innerJoin('f', 'face_recognition_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')));
		if ($onlyWithoutPersons) {
			$qb = $qb->andWhere($qb->expr()->isNull('person'));
		}
		$query = $qb
			->setParameter('user', $userId)
			->setParameter('model', $model);
		$resultStatement = $query->execute();
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
	public function getOldestCreatedFaceWithoutPerson(string $userId, int $model): Face {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('f.id', 'f.creation_time')
			->from('face_recognition_faces', 'f')
			->innerJoin('f', 'face_recognition_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->isNull('person'))
			->orderBy('f.creation_time', 'ASC');
		$cursor = $qb->execute();
		$row = $cursor->fetch();
		if($row === false) {
			$cursor->closeCursor();
			throw new DoesNotExistException("No faces found and we should have at least one");
		}
		$face = $this->mapRowToEntity($row);
		$cursor->closeCursor();
		return $face;
	}

	public function getFaces(string $userId, $model): array {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select('f.id', 'f.person', 'f.descriptor')
			->from('face_recognition_faces', 'f')
			->innerJoin('f', 'face_recognition_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $userId)
			->setParameter('model', $model);
		$faces = $this->findEntities($qb);
		return $faces;
	}

	public function findFacesFromPerson(string $userId, int $personId, int $model, $limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.image', 'f.person')
			->from('face_recognition_faces', 'f')
			->innerJoin('f', 'face_recognition_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('person', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)));

		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		$faces = $this->findEntities($qb);
		return $faces;
	}


	public function getPersonOnFile(string $userId, int $personId, int $fileId, int $model): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'left', 'right', 'top', 'bottom')
			->from('face_recognition_faces', 'f')
			->innerJoin('f', 'face_recognition_persons' ,'p', $qb->expr()->eq('f.person', 'p.id'))
			->innerJoin('f', 'face_recognition_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('p.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('person', $qb->createParameter('person')))
			->andWhere($qb->expr()->eq('file', $qb->createParameter('file_id')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('p.is_valid', $qb->createParameter('is_valid')))
			->setParameter('user', $userId)
			->setParameter('person', $personId)
			->setParameter('file_id', $fileId)
			->setParameter('model', $model)
			->setParameter('is_valid', true);
		$faces = $this->findEntities($qb);
		return $faces;
	}

	/**
	 * @param int $imageId Image for which to delete faces for
	 */
	public function removeFaces(int $imageId) {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId)))
			->execute();
	}

	/**
	 * Deletes all faces from that user.
	 *
	 * @param string $userId User to drop faces from table.
	 */
	public function deleteUserFaces(string $userId) {
		$sub = $this->db->getQueryBuilder();
		$sub->select(new Literal('1'));
		$sub->from("face_recognition_images", "i")
			->where($sub->expr()->eq('i.id', '*PREFIX*' . $this->getTableName() .'.image'))
			->andWhere($sub->expr()->eq('i.user', $sub->createParameter('user')));

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where('EXISTS (' . $sub->getSQL() . ')')
			->setParameter('user', $userId)
			->execute();
	}

}

<?php
namespace OCA\FaceRecognition\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\Mapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class FaceNewMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition_faces', '\OCA\FaceRecognition\Db\FaceNew');
	}

	public function countFaces(string $userId, $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('f.id') . ')'))
			->from('face_recognition_faces', 'f')
			->innerJoin('f', 'face_recognition_images' ,'i', $qb->expr()->eq('f.image', 'i.id'))
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $userId)
			->setParameter('model', $model);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
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
		$faces = $this->frFindEntities($qb);
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
	 * Runs a sql query and returns an array of entities
	 *
	 * todo: stolen from QBMapper. However, this class is in use from 14.0 only.
	 * If we use it, we are "locked" ourselves to versions >= 14.0
	 *
	 * @param IQueryBuilder $query
	 * @return Entity[] all fetched entities
	 * @since 14.0.0
	 */
	protected function frFindEntities(IQueryBuilder $query): array {
		$cursor = $query->execute();

		$entities = [];

		while($row = $cursor->fetch()){
			$entities[] = $this->mapRowToEntity($row);
		}

		$cursor->closeCursor();

		return $entities;
	}
}

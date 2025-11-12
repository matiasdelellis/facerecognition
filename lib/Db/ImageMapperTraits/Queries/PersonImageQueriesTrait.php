<?php
namespace OCA\FaceRecognition\Db\ImageMapperTraits\Queries;

trait PersonImageQueriesTrait
{
	/**
	 * Find images for a specific person belonging to a user and model.
	 *
	 * @param string $userId Id of user
	 * @param int $modelId Model Id to get images for
	 * @param string $name Name of person
	 * @param int|null $offset Offset for pagination
	 * @param int|null $limit Limit for pagination
	 * @return array Array of Image entities
	 */
	public function findFromPerson(string $userId, int $modelId, string $name, ?int $offset = null, ?int $limit = null): array {
		try {
			$qb = $this->getAllFileds();
			$qb->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image_id', 'i.id'))
				->innerJoin('i', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
				->innerJoin('i', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'cf.cluster_id'))
				->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
				->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
				->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(true)))
				->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)))
				->orderBy('i.nc_file_id', 'DESC');

			if ($offset !== null) {
				$qb->setFirstResult($offset);
			}
			if ($limit !== null) {
				$qb->setMaxResults($limit);
			}

			$images = $this->findEntities($qb);

			$this->logDebug('Found images for person', [
				'uid'     => $userId,
				'modelId' => $modelId,
				'person'  => $name,
				'offset'  => $offset,
				'limit'   => $limit,
				'count'   => count($images),
                'sql' => $qb->getSQL(),
			]);

			return $images;

		} catch (\Throwable $e) {
			$this->logError('Failed to find images for person', [
				'uid'       => $userId,
				'modelId'   => $modelId,
				'person'    => $name,
				'offset'    => $offset,
				'limit'     => $limit,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Count the number of processed images for a specific person belonging to a user and model.
	 *
	 * @param string $userId Id of user
	 * @param int $modelId Model Id to get images for
	 * @param string $name Name of person
	 * @return int Number of images
	 */
	public function countFromPerson(string $userId, int $modelId, string $name): int {
		try {
			$qb = $this->db->getQueryBuilder();

			$qb->select($qb->func()->count('*'))
				->from($this->getTableName(), 'i')
				->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
				->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image_id', 'i.id'))
				->innerJoin('i', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
				->innerJoin('i', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'cf.cluster_id'))
				->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
				->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
				->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(true)))
				->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)));

			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();

			$this->logDebug('Counted images for person', [
				'uid'     => $userId,
				'modelId' => $modelId,
				'person'  => $name,
				'count'   => $count,
                'sql' => $qb->getSQL(),
			]);

			return $count;

		} catch (\Throwable $e) {
			$this->logError('Failed to count images for person', [
				'uid'       => $userId,
				'modelId'   => $modelId,
				'person'    => $name,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
<?php
namespace OCA\FaceRecognition\Db\FaceMapperTraits;

trait CountFacesTrait {
	/**
	 * Counts all the faces that belong to images of a given user, created using given model.
	 *
	 * @param string $userId User to which faces and associated images belong
	 * @param int $model Model ID
	 * @param bool $onlyWithoutPersons True if only faces without a person association should be counted
	 *
	 * @return int
	 */
	public function countFaces(string $userId, int $model, bool $onlyWithoutPersons = false): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->createFunction('COUNT(f.id)'))
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')));

		if ($onlyWithoutPersons) {
			$qb->andWhere($qb->expr()->isNull('cf.cluster_id'));
		}

		$qb->setParameter('user', $userId)
		   ->setParameter('model', $model);

		try {
			$resultStatement = $qb->executeQuery();
			$data = $resultStatement->fetch(\PDO::FETCH_NUM);
			$resultStatement->closeCursor();

			$count = (int) $data[0];

			$this->logDebug('Counted faces for user/model', [
				'userId' => $userId,
				'modelId' => $model,
				'onlyWithoutPersons' => $onlyWithoutPersons,
				'count' => $count,
			]);

			return $count;

		} catch (\Throwable $e) {
			$this->logError('Error counting faces', [
				'userId' => $userId,
				'modelId' => $model,
				'onlyWithoutPersons' => $onlyWithoutPersons,
				'error' => $e->getMessage(),
			]);

			throw $e;
		}
	}
}
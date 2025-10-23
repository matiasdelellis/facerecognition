<?php
namespace OCA\FaceRecognition\Db\FaceMapperTraits;

use OCA\FaceRecognition\Db\Face;

trait AllFacesTrait {
	/**
	 * Gets all faces that belong to images of a given user, created using given model.
	 *
	 * Used only in tests!
	 *
	 * @param string $userId User to which faces and associated images belong
	 * @param int $model Model ID
	 * @return Face[]
	 */
	public function getFaces(string $userId, int $model): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select(
				'f.id',
				'cf.cluster_id AS person',
				'f.image_id AS image',
				'f.x',
				'f.y',
				'f.width',
				'f.height',
				'f.landmarks',
				'f.descriptor',
				'f.confidence',
				'f.creation_time',
				$qb->createFunction("COALESCE(cf.is_groupable, TRUE) AS is_groupable")
			)
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->leftJoin(
				'f',
				'facerecog_clusters',
				'c',
				$qb->expr()->orX(
					$qb->expr()->isNull('cf.face_id'),
					$qb->expr()->eq('f.id', 'cf.face_id')
				)
			)
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('c.user'),
					$qb->expr()->eq('c.user', $qb->createParameter('user'))
				)
			)
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->groupBy('f.id')
			->setParameter('user', $userId)
			->setParameter('model', $model);

		try {
			$faces = $this->findEntities($qb);

			$this->logDebug('Retrieved faces for user and model (used in tests)', [
				'userId' => $userId,
				'modelId' => $model,
				'count' => count($faces),
			]);

			return $faces;

		} catch (\Throwable $e) {
			$this->logError('Error retrieving faces (used in tests)', [
				'userId' => $userId,
				'modelId' => $model,
				'error' => $e->getMessage(),
			]);
			throw $e;
		}
	}
}
<?php
namespace OCA\FaceRecognition\Db\FaceMapperTraits;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Face;

trait GroupingTrait {
	/**
	 * Gets all faces that belong to images of a given user, created using given model
	 * and that are groupable (size and confidence above threshold).
	 *
	 * @param string $userId User to which faces and associated images belong
	 * @param int $model Model ID
	 * @param int $minSize Minimum size (width and height) for face to be considered groupable
	 * @param float $minConfidence Minimum confidence for face to be considered groupable
	 *
	 * @return Face[]
	 */
	public function getGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select(
				'f.id',
				$qb->createFunction("CASE WHEN c.user = " . $qb->createParameter('user') . " THEN cf.cluster_id ELSE NULL END AS person"),
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
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->leftJoin(
				'f',
				'facerecog_clusters',
				'c',
				$qb->expr()->andX(
					$qb->expr()->eq('c.id', 'cf.cluster_id'),
					$qb->expr()->eq('c.user', $qb->createParameter('user'))
				)
			)
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->andWhere($qb->expr()->gte('f.width', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('f.height', $qb->createParameter('min_size')))
			->andWhere($qb->expr()->gte('f.confidence', $qb->createParameter('min_confidence')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('cf.is_groupable', $qb->createParameter('is_groupable')),
					$qb->expr()->isNull('cf.is_groupable')
				)
			)
			->orderBy('f.id', 'ASC')
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', true, IQueryBuilder::PARAM_BOOL);

		try {
			$faces = $this->findEntities($qb);

			$this->logDebug('Retrieved groupable faces', [
				'userId' => $userId,
				'modelId' => $model,
				'minSize' => $minSize,
				'minConfidence' => $minConfidence,
				'count' => count($faces),
				'is_groupable' => true,
                'sql' => $qb->getSQL(),
			]);

			return $faces;

		} catch (\Throwable $e) {
			$this->logError('Error retrieving groupable faces', [
				'userId' => $userId,
				'modelId' => $model,
				'minSize' => $minSize,
				'minConfidence' => $minConfidence,
				'is_groupable' => true,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Gets all faces that belong to images of a given user, created using a given model,
	 * and that are NOT groupable (size or confidence below threshold, or explicitly marked non-groupable).
	 *
	 * @param string $userId User to which faces and associated images belong
	 * @param int $model Model ID
	 * @param int $minSize Minimum size (width and height) for a face to be considered groupable
	 * @param float $minConfidence Minimum confidence for a face to be considered groupable
	 *
	 * @return Face[]
	 */
	public function getNonGroupableFaces(string $userId, int $model, int $minSize, float $minConfidence): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select(
				'f.id',
				$qb->createFunction("CASE WHEN c.user = " . $qb->createParameter('user') . " THEN cf.cluster_id ELSE NULL END AS person"),
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
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
			->leftJoin(
				'f',
				'facerecog_clusters',
				'c',
				$qb->expr()->andX(
					$qb->expr()->eq('c.id', 'cf.cluster_id'),
					$qb->expr()->eq('c.user', $qb->createParameter('user'))
				)
			)
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->lt('f.width', $qb->createParameter('min_size')),
					$qb->expr()->lt('f.height', $qb->createParameter('min_size')),
					$qb->expr()->lt('f.confidence', $qb->createParameter('min_confidence')),
					$qb->expr()->eq('cf.is_groupable', $qb->createParameter('is_groupable'))
				)
			)
			->orderBy('f.id', 'ASC')
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', false, IQueryBuilder::PARAM_BOOL);

		try {
			$faces = $this->findEntities($qb);

			$this->logDebug('FaceMapper::getNonGroupableFaces — Query executed successfully', [
				'user' => $userId,
				'model' => $model,
				'minSize' => $minSize,
				'minConfidence' => $minConfidence,
				'found' => count($faces),
				'is_groupable' => false,
                'sql' => $qb->getSQL(),
			]);

			return $faces;
		} catch (\Throwable $e) {
			$this->logError('FaceMapper::getNonGroupableFaces — Query failed', [
				'user' => $userId,
				'model' => $model,
				'minSize' => $minSize,
				'minConfidence' => $minConfidence,
				'is_groupable' => false,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
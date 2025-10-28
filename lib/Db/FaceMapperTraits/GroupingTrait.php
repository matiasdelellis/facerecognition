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

		// Subquery: cluster_id
		$subClusterId = $this->db->getQueryBuilder();
		$subClusterId
			->select('c.cluster_id')
			->from('facerecog_cluster_faces', 'c')
			->join('c', 'facerecog_clusters', 'cl', $subClusterId->expr()->eq('c.cluster_id', 'cl.id'))
			->where(
				$subClusterId->expr()->andX(
					$subClusterId->expr()->eq('c.face_id', 'f.id'),
					$subClusterId->expr()->eq('cl.user', 'iu.user')
				)
			)
			->setMaxResults(1);

		// Subquery: is_groupable (with COALESCE default TRUE)
		$subIsGroupable = $this->db->getQueryBuilder();
		$subIsGroupable
			->select('c.is_groupable')
			->from('facerecog_cluster_faces', 'c')
			->join('c', 'facerecog_clusters', 'cl', $subIsGroupable->expr()->eq('c.cluster_id', 'cl.id'))
			->where(
				$subIsGroupable->expr()->andX(
					$subIsGroupable->expr()->eq('c.face_id', 'f.id'),
					$subIsGroupable->expr()->eq('cl.user', 'iu.user')
				)
			)
			->setMaxResults(1);

		// Main query
		$qb->select(
				'f.id',
				'f.image_id AS image',
				'f.x',
				'f.y',
				'f.width',
				'f.height',
				'f.landmarks',
				'f.descriptor',
				'f.confidence',
				'f.creation_time',
				$qb->createFunction('(' . $subClusterId->getSQL() . ') AS person'),
				$qb->createFunction('COALESCE((' . $subIsGroupable->getSQL() . '), TRUE) AS is_groupable')
			)
			->from('facerecog_faces', 'f')
			->join('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->join('i', 'facerecog_user_images', 'iu', $qb->expr()->eq('i.id', 'iu.image_id'))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('iu.user', $qb->createParameter('user')),
					$qb->expr()->eq('i.model', $qb->createParameter('model')),
					$qb->expr()->gte('f.width', $qb->createParameter('min_size')),
					$qb->expr()->gte('f.height', $qb->createParameter('min_size')),
					$qb->expr()->gte('f.confidence', $qb->createParameter('min_confidence')),
					$qb->expr()->eq(
						$qb->createFunction('COALESCE((' . $subIsGroupable->getSQL() . '), TRUE)'),
						$qb->createParameter('is_groupable')
					)
				)
			)

		// Bind parameters
			->orderBy('f.id', 'ASC')
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('min_size', $minSize)
			->setParameter('min_confidence', $minConfidence)
			->setParameter('is_groupable', true, IQueryBuilder::PARAM_BOOL);
		try {
			$faces = $this->findEntities($qb);

			$this->logInfo('Retrieved groupable faces', [
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
		// Subquery: cluster_id
		$subClusterId = $this->db->getQueryBuilder();
		$subClusterId
			->select('c.cluster_id')
			->from('facerecog_cluster_faces', 'c')
			->join('c', 'facerecog_clusters', 'cl', $subClusterId->expr()->eq('c.cluster_id', 'cl.id'))
			->where(
				$subClusterId->expr()->andX(
					$subIsGroupable->expr()->eq('c.face_id', 'f.id'),
					$subIsGroupable->expr()->eq('cl.user', 'iu.user')
				)
			)
			->setMaxResults(1);

		// Subquery: is_groupable (with COALESCE default TRUE)
		$subIsGroupable = $this->db->getQueryBuilder();
		$subIsGroupable
			->select('c.is_groupable')
			->from('facerecog_cluster_faces', 'c')
			->join('c', 'facerecog_clusters', 'cl', $subIsGroupable->expr()->eq('c.cluster_id', 'cl.id'))
			->where(
				$subIsGroupable->expr()->andX(
					$subIsGroupable->expr()->eq('c.face_id', 'f.id'),
					$subIsGroupable->expr()->eq('cl.user', 'iu.user')
				)
			)
			->setMaxResults(1);

		// Main query
		$qb->select(
				'f.id',
				'f.image_id AS image',
				'f.x',
				'f.y',
				'f.width',
				'f.height',
				'f.landmarks',
				'f.descriptor',
				'f.confidence',
				'f.creation_time',
				$qb->createFunction('(' . $subClusterId->getSQL() . ') AS person'),
				$qb->createFunction('COALESCE((' . $subIsGroupable->getSQL() . '), TRUE) AS is_groupable')
			)
			->from('facerecog_faces', 'f')
			->join('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->join('i', 'facerecog_user_images', 'iu', $qb->expr()->eq('i.id', 'iu.image_id'))
			->where($qb->expr()->eq('iu.user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->lt('f.width', $qb->createParameter('min_size')),
					$qb->expr()->lt('f.height', $qb->createParameter('min_size')),
					$qb->expr()->lt('f.confidence', $qb->createParameter('min_confidence')),
					$qb->expr()->eq(
						$qb->createFunction('COALESCE((' . $subIsGroupable->getSQL() . '), TRUE)'),
						$qb->createParameter('is_groupable')
					)
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
<?php
namespace OCA\FaceRecognition\Db\FaceMapperTraits;

use OCP\AppFramework\Db\DoesNotExistException;
use OCA\FaceRecognition\Db\Face;

trait FindFacesTrait {
	public function find(int $faceId, string $userId): ?Face {
		$qb = $this->db->getQueryBuilder();

		$subPerson = "
			(SELECT cf_inner.cluster_id
			FROM *PREFIX*facerecog_cluster_faces cf_inner
			JOIN *PREFIX*facerecog_clusters c_inner ON c_inner.id = cf_inner.cluster_id
			WHERE cf_inner.face_id = f.id
			AND c_inner.user = " . $qb->createParameter('user') . "
			ORDER BY cf_inner.cluster_id
			LIMIT 1
			)
		";

		$subIsGroupable = "
			(SELECT cf_inner.is_groupable
			FROM *PREFIX*facerecog_cluster_faces cf_inner
			JOIN *PREFIX*facerecog_clusters c_inner ON c_inner.id = cf_inner.cluster_id
			WHERE cf_inner.face_id = f.id
			AND c_inner.user = " . $qb->createParameter('user') . "
			ORDER BY cf_inner.cluster_id
			LIMIT 1
			)
		";

		$qb->select(
				'f.id',
				$qb->createFunction($subPerson . ' AS person'),
				'f.image_id AS image',
				'f.x',
				'f.y',
				'f.width',
				'f.height',
				'f.landmarks',
				'f.descriptor',
				'f.confidence',
				'f.creation_time',
				$qb->createFunction("COALESCE(" . $subIsGroupable . ", TRUE) AS is_groupable")
			)
			->from($this->getTableName(), 'f')
			->where($qb->expr()->eq('f.id', $qb->createParameter('faceId')))
			->setParameter('user', $userId)
			->setParameter('faceId', $faceId);

		try {
			$entity = $this->findEntity($qb);

			// Use trait helper (auto adds method, file, line, app)
			$this->logDebug("Found face entity", [
				'faceId' => $faceId,
				'userId' => $userId,
			]);

			return $entity;

		} catch (DoesNotExistException $e) {
			$this->logInfo("No face found", [
				'userId' => $userId,
				'faceId' => $faceId,
			]);
			return null;

		} catch (\Throwable $e) {
			$this->logError("Error finding face entity", [
				'faceId' => $faceId,
				'userId' => $userId,
				'error'  => $e->getMessage(),
			]);
			throw $e;
		}
	}

	/**
	 * Based on a given fileId, takes all faces that belong to that file
	 * and return an array with that.
	 *
	 * @param string $userId ID of the user that faces belong to
	 * @param int $modelId ID of the model that faces belong to
	 * @param int $fileId ID of file for which to search faces
	 *
	 * @return Face[]
	 */
	public function findFromFile(string $userId, int $modelId, int $fileId): array {
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
				$qb->createFunction('COALESCE(cf.is_groupable, TRUE) AS is_groupable')
			)
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('f', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->leftJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
			->leftJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('cf.cluster_id', 'c.id'))
			->where($qb->expr()->eq('ui.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
			->andWhere($qb->expr()->eq('i.nc_file_id', $qb->createParameter('nc_file_id')))
			->setParameter('user_id', $userId)
			->setParameter('model_id', $modelId)
			->setParameter('nc_file_id', $fileId)
			->orderBy('f.confidence', 'DESC');

		try {
			$faces = $this->findEntities($qb);

			$this->logDebug('Found faces for file', [
				'userId' => $userId,
				'modelId' => $modelId,
				'fileId' => $fileId,
				'count' => count($faces),
			]);

			return $faces;

		} catch (\Throwable $e) {
			$this->logError('Error retrieving faces from file', [
				'userId' => $userId,
				'modelId' => $modelId,
				'fileId' => $fileId,
				'error' => $e->getMessage(),
			]);

			throw $e;
		}
	}

    /**
     * Gets all faces that belong to a cluster of a given user, created using a given model.
     *
     * @param string $userId User to which faces and associated images belong
     * @param int $clusterId Cluster ID
     * @param int $model Model ID
     * @param int|null $limit Optional limit for number of results
     * @param int|null $offset Optional offset for pagination
     *
     * @return Face[]
     */
    public function findFromCluster(string $userId, int $clusterId, int $model, ?int $limit = null, ?int $offset = null): array {
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
                'f.creation_time'
            )
            ->from($this->getTableName(), 'f')
            ->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
            ->innerJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
            ->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('c.id', 'cf.cluster_id'))
            ->where($qb->expr()->eq('c.user', $qb->createParameter('user')))
            ->andWhere($qb->expr()->eq('cf.cluster_id', $qb->createParameter('cluster')))
            ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
            ->orderBy('f.id', 'ASC')
            ->setParameter('user', $userId)
            ->setParameter('cluster', $clusterId)
            ->setParameter('model', $model);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        try {
            $faces = $this->findEntities($qb);
            $this->logDebug('FaceMapper -- findFromCluster', [
                'user' => $userId,
                'clusterId' => $clusterId,
                'model' => $model,
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($faces)
            ]);
            return $faces;
        } catch (\Throwable $e) {
            $this->logError('FaceMapper -- findFromCluster -- Error fetching faces', [
                'user' => $userId,
                'clusterId' => $clusterId,
                'model' => $model,
                'limit' => $limit,
                'offset' => $offset,
                'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Finds all faces belonging to a given person for a user and model.
     *
     * @param string $userId User ID owning the faces
     * @param string $personId Person name or identifier
     * @param int $model Model ID
     * @param int|null $limit Optional result limit
     * @param int|null $offset Optional result offset
     *
     * @return Face[]
     */
    public function findFromPerson(string $userId, string $personId, int $model, ?int $limit = null, ?int $offset = null): array {
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
                'f.creation_time'
            )
            ->from($this->getTableName(), 'f')
            ->innerJoin('f', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('f.id', 'cf.face_id'))
            ->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('c.id', 'cf.cluster_id'))
            ->innerJoin('f', 'facerecog_person_clusters', 'cp', $qb->expr()->eq('cp.cluster_id', 'c.id'))
            ->innerJoin('f', 'facerecog_persons', 'p', $qb->expr()->eq('p.id', 'cp.person_id'))
            ->innerJoin('f', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
            ->where($qb->expr()->eq('c.user', $qb->createParameter('user')))
            ->andWhere($qb->expr()->eq('p.name', $qb->createParameter('person')))
            ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
            ->orderBy('i.nc_file_id', 'DESC')
            ->setParameter('user', $userId)
            ->setParameter('person', $personId)
            ->setParameter('model', $model);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        try {
            $faces = $this->findEntities($qb);

            $this->logDebug('FaceMapper -- findFromPerson', [
                'userId' => $userId,
                'personId' => $personId,
                'model' => $model,
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($faces),
            ]);

            return $faces;

        } catch (\Throwable $e) {
            $this->logError('FaceMapper -- findFromPerson -- Error fetching faces', [
                'userId' => $userId,
                'personId' => $personId,
                'model' => $model,
                'limit' => $limit,
                'offset' => $offset,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Finds all faces contained in one image
     * Note that this is independent of any Model
     *
     * @param int $imageId Image for which to find all faces
     * @return Face[]
     */
    public function findByImage(int $imageId): array {
        $qb = $this->db->getQueryBuilder();

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
                'f.creation_time'
            )
            ->from($this->getTableName(), 'f')
            ->where($qb->expr()->eq('f.image_id', $qb->createParameter('image')))
            ->setParameter('image', $imageId);

        try {
            $faces = $this->findEntities($qb);
            $this->logDebug('Found faces by image', [
                'imageId' => $imageId,
                'count' => count($faces),
            ]);
            return $faces;
        } catch (\Throwable $e) {
            $this->logError('Error finding faces by image', [
                'imageId' => $imageId,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

	/**
	 * Gets oldest created face from database, for a given user and model, that is not associated with a person.
	 *
	 * @param string $userId User to which faces and associated images belong
	 * @param int $model Model ID
	 *
	 * @return Face|null Oldest face, if any is found
	 * @throws DoesNotExistException If there is no face without a person for the given user and model
	 */
	public function getOldestCreatedFaceWithoutPerson(string $userId, int $model): ?Face {
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
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->isNull('cf.cluster_id'))
			->orderBy('f.creation_time', 'ASC')
			->setMaxResults(1);

		try {
			$face = $this->findEntity($qb);

			$this->logDebug('Found oldest face without person', [
				'userId' => $userId,
				'modelId' => $model,
				'faceId' => $face->getId(),
			]);

			return $face;

		} catch (DoesNotExistException $e) {
			$this->logInfo('No face without person found', [
				'userId' => $userId,
				'modelId' => $model,
			]);
			return null;

		} catch (\Throwable $e) {
			$this->logError('Error retrieving oldest face without person', [
				'userId' => $userId,
				'modelId' => $model,
				'error' => $e->getMessage(),
			]);
			throw $e;
		}
	}
}
<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\AppFramework\Db\DoesNotExistException;
use OCA\FaceRecognition\Db\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

trait ClusterFinderTrait
{
    /**
     * @param string $userId ID of the user
     * @param int $clusterId ID of the person cluster
     *
     * @return Person
     */
    public function find(string $userId, int $clusterId): Person {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.id', 'user', 'p.name', 'is_visible', 'is_valid', 'last_generation_time', 'linked_user')
                ->from($this->getTableName(), 'c')
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', 
                    $qb->expr()->andX(
                        $qb->expr()->eq('pc.person_id', 'p.id'), 
                        $qb->expr()->isNotNull('pc.cluster_id')
                    )
                )
                ->where($qb->expr()->eq('c.id', $qb->createNamedParameter($clusterId)))
                ->andWhere($qb->expr()->eq('c.user', $qb->createNamedParameter($userId)));

            $entity = $this->findEntity($qb);

            $this->logDebug('Found cluster', [
                'clusterId' => $clusterId,
                'userId'    => $userId,
                'sql' => $qb->getSQL(),
            ]);

            return $entity;

        } catch (DoesNotExistException $e) {
            $this->logInfo('No cluster found', [
                'clusterId' => $clusterId,
                'userId'    => $userId,
                'sql' => $qb->getSQL(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Unexpected error', [
                'clusterId' => $clusterId,
                'userId'    => $userId,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * @param string $personName Name of the person to find
     * 
     * @return Person[]
     */
    public function findByName(string $userId, int $modelId, string $personName): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                    'c.id', 
                    'c.user', 
                    'p.name', 
                    'c.is_visible', 
                    'c.is_valid', 
                    'c.last_generation_time', 
                    'c.linked_user'
                )
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', 
                    $qb->expr()->andX(
                        $qb->expr()->eq('pc.person_id', 'p.id'), 
                        $qb->expr()->isNotNull('pc.cluster_id')
                    )
                )
                ->where($qb->expr()->eq('p.name', $qb->createParameter('person_name')))
                ->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->groupBy('c.id', 'p.name')
                ->setParameter('user_id', $userId)
                ->setParameter('model_id', $modelId)
                ->setParameter('person_name', $personName);

            $entities = $this->findEntities($qb);

            $this->logDebug('Found clusters', [
                'userId'     => $userId,
                'modelId'    => $modelId,
                'personName' => $personName,
                'count'      => count($entities),
                'sql' => $qb->getSQL(),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to find clusters', [
                'userId'     => $userId,
                'modelId'    => $modelId,
                'personName' => $personName,
                'sql' => $qb->getSQL(),
                'exception'  => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * 
     * @return Person[]
     */
    public function findUnassigned(string $userId, int $modelId): array {
        try {
            $entities = $this->getPersonsByFlagsWithoutName($userId, $modelId, true, true);

            $this->logDebug('Found persons', [
                'userId'  => $userId,
                'modelId' => $modelId,
                'count'   => count($entities),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to fetch unassigned persons', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * 
     * @return Person[]
     */
    public function findIgnored(string $userId, int $modelId): array {
        try {
            $entities = $this->getPersonsByFlagsWithoutName($userId, $modelId, true, false);

            $this->logDebug('Found persons', [
                'userId'  => $userId,
                'modelId' => $modelId,
                'count'   => count($entities),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to fetch ignored persons', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * 
     * @return Person[]
     */
    public function findAll(string $userId, int $modelId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                    'c.id', 
                    'c.user', 
                    'p.name', 
                    'c.is_visible', 
                    'c.is_valid', 
                    'c.last_generation_time', 
                    'c.linked_user'
                )
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->groupBy('c.id', 'p.name')
                ->setParameter('user_id', $userId)
                ->setParameter('model_id', $modelId);

            $entities = $this->findEntities($qb);

            $this->logDebug('Found clusters', [
                'userId'  => $userId,
                'modelId' => $modelId,
                'count'   => count($entities),
                'sql' => $qb->getSQL(),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to fetch clusters', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     *
     * @return Person[]
     */
    public function findDistinctNames(string $userId, int $modelId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('p.name')
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->isNotNull('p.name'))
                ->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->setParameter('user_id', $userId)
                ->setParameter('model_id', $modelId);

            $entities = $this->findEntities($qb);

            $this->logDebug('Found distinct names', [
                'userId'   => $userId,
                'modelId'  => $modelId,
                'count'    => count($entities),
                'sql' => $qb->getSQL(),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to fetch distinct names', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * @param array|string $faceNames Face names to filter
     *
     * @return Person[]
     */
    public function findDistinctNamesSelected(string $userId, int $modelId, $faceNames): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('p.name')
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->andWhere($qb->expr()->isNotNull('p.name'))
                ->andWhere($qb->expr()->eq('p.name', $qb->createParameter('faceNames')))
                ->setParameter('user_id', $userId)
                ->setParameter('model_id', $modelId)
                ->setParameter('faceNames', $faceNames);

            $entities = $this->findEntities($qb);

            $this->logDebug('Found selected distinct names', [
                'userId'   => $userId,
                'modelId'  => $modelId,
                'count'    => count($entities),
                'sql' => $qb->getSQL(),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to fetch selected distinct names', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'faceNames' => $faceNames,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Search Person by name (case-insensitive, partial match)
     *
     * @param string $userId ID of the user
     * @param int $modelId Model ID
     * @param string $name Name to search
     * @param int|null $offset Pagination offset
     * @param int|null $limit Pagination limit
     *
     * @return Person[]
     */
    public function findPersonsLike(string $userId, int $modelId, string $name, ?int $offset = null, ?int $limit = null): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('p.name')
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(true)))
                ->andWhere($qb->expr()->like($qb->func()->lower('p.name'), $qb->createParameter('query')))
                ->setFirstResult($offset)
                ->setMaxResults($limit);

            $query = '%' . $this->db->escapeLikeParameter(strtolower($name)) . '%';
            $qb->setParameter('query', $query)
                ->setParameter('user_id', $userId)
                ->setParameter('model_id', $modelId);

            $entities = $this->findEntities($qb);

            $this->logDebug('Search completed', [
                'userId' => $userId,
                'modelId' => $modelId,
                'name' => $name,
                'count' => count($entities),
                'offset' => $offset,
                'limit' => $limit,
                'sql' => $qb->getSQL(),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to search persons', [
                'userId' => $userId,
                'modelId' => $modelId,
                'name' => $name,
                'offset' => $offset,
                'limit' => $limit,
                'sql' => $qb->getSQL(),
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * @param bool $isValid
     * @param bool $isVisible
     * 
     * @return Person[]
     */
    public function getPersonsByFlagsWithoutName(string $userId, int $modelId, bool $isValid, bool $isVisible): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                    'c.id', 
                    'c.user', 
                    'c.is_visible', 
                    'c.is_valid', 
                    'c.last_generation_time',
                    'c.linked_user'
                )
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->eq('c.is_valid', $qb->createParameter('is_valid')))
                ->andWhere($qb->expr()->eq('c.is_visible', $qb->createParameter('is_visible')))
                ->andWhere($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->andWhere($qb->expr()->isNull('name'))
                ->groupBy('c.id')
                ->setParameter('user_id', $userId, IQueryBuilder::PARAM_STR)
                ->setParameter('model_id', $modelId, IQueryBuilder::PARAM_INT)
                ->setParameter('is_valid', $isValid, IQueryBuilder::PARAM_BOOL)
                ->setParameter('is_visible', $isVisible, IQueryBuilder::PARAM_BOOL);

            $entities = $this->findEntities($qb);

            $this->logDebug('Found clusters', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'isValid'   => $isValid,
                'isVisible' => $isVisible,
                'count'     => count($entities),
                'sql' => $qb->getSQL(),
            ]);

            return $entities;

        } catch (\Throwable $e) {
            $this->logError('Failed to fetch clusters', [
                'userId'    => $userId,
                'modelId'   => $modelId,
                'isValid'   => $isValid,
                'isVisible' => $isVisible,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
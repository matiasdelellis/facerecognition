<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Person;

trait ClusterCountTrait
{
    /**
     * Returns count of distinct persons found for a given user and model.
     *
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     *
     * @return int Count of persons
     */
    public function countPersons(string $userId, int $modelId): int {
        try {
            $count = count($this->findDistinctNames($userId, $modelId));

            $this->logInfo('Count completed', [
                'userId' => $userId,
                'modelId' => $modelId,
                'count' => $count
            ]);

            return $count;

        } catch (\Throwable $e) {
            $this->logError('Failed to count persons', [
                'userId' => $userId,
                'modelId' => $modelId,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Returns count of clusters found for a given user.
     *
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     * @param bool $onlyInvalid True if only invalid clusters should be counted
     *
     * @return int Count of clusters
     */
    public function countClusters(string $userId, int $modelId, bool $onlyInvalid = false): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('c.id') . ') OVER ()'))
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $qb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $qb->expr()->eq('f.image_id', 'i.id'))
                ->innerJoin('c', 'facerecog_user_images', 'ui', $qb->expr()->eq('i.id', 'ui.image_id'))
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->eq('c.user', $qb->createParameter('user_id')))
                ->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model_id')))
                ->groupBy('c.id')
                ->setParameter('user_id', $userId)
                ->setParameter('model_id', $modelId);

            if ($onlyInvalid) {
                $qb->andWhere($qb->expr()->eq('c.is_valid', $qb->createParameter('is_valid')))
                ->setParameter('is_valid', false, IQueryBuilder::PARAM_BOOL);
            }

            $resultStatement = $qb->executeQuery();
            $data = $resultStatement->fetch(\PDO::FETCH_NUM);
            $resultStatement->closeCursor();

            $count = $data !== false ? (int)$data[0] : 0;

            $this->logInfo('Count completed', [
                'userId' => $userId,
                'modelId' => $modelId,
                'onlyInvalid' => $onlyInvalid,
                'count' => $count,
                'sql' => $qb->getSQL(),
            ]);

            return $count;

        } catch (\Throwable $e) {
            $this->logError('Failed to count clusters', [
                'userId' => $userId,
                'modelId' => $modelId,
                'onlyInvalid' => $onlyInvalid,
                'sql' => $qb->getSQL(),
                'exception' => $e
            ]);
            throw $e;
        }
    }
    
    /**
     * Returns the count of faces associated with a cluster.
     *
     * @param int $clusterId ID of the cluster
     * @return int
     */
    public function countClusterFaces(int $clusterId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $resultStatement = $qb
                ->select($qb->func()->count('*'))
                ->from('facerecog_cluster_faces')
                ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
                ->executeQuery();

            $data = $resultStatement->fetch(\PDO::FETCH_NUM);
            $resultStatement->closeCursor();

            $count = (int)$data[0];

            $this->logDebug('Count cluster\'s faces', [
                'clusterId' => $clusterId,
                'count' => $count,
                'sql' => $qb->getSQL(),
            ]);

            return $count;
        } catch (\Throwable $e) {
            $this->logError('Failed to count clusters faces', [
                'clusterId' => $clusterId,
                'sql' => $qb->getSQL(),
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
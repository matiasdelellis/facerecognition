<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Person;
use OCP\IDBConnection;

trait ClusterFaceTrait
{
    /**
     * Updates a face assignment to a cluster (person) in the database.
     *
     * @param int $faceId ID of the face
     * @param int|null $oldCluster ID of the old cluster (person), NULL if new assignment
     * @param int|null $clusterId ID of the new cluster (person), NULL if removing assignment
     * @param bool $isGroupable Whether the face is groupable
     *
     * @return void
     */
    public function updateFace(int $faceId, ?int $oldCluster, ?int $clusterId, bool $isGroupable): void {
        try {
            // Validate input
            if ($oldCluster === null && $clusterId === null) {
                throw new \InvalidArgumentException('No clusterId was given for face ID: ' . $faceId);
            }

            // CASE 1: Attach face to a new cluster
            if ($oldCluster === null) {
                $this->attachFaceToCluster($clusterId, $faceId, $isGroupable);
                $this->logDebug('Attached face to new cluster', [
                    'faceId' => $faceId,
                    'clusterId' => $clusterId,
                    'isGroupable' => $isGroupable,
                ]);
                return;
            }

            // CASE 2: Detach face (no new cluster)
            if ($clusterId === null) {
                $this->detachFace($oldCluster, $faceId);
                $this->logDebug('Detached face from cluster', [
                    'faceId' => $faceId,
                    'oldCluster' => $oldCluster,
                ]);
                return;
            }

            // CASE 3: Move face from one cluster to another
            $qb = $this->db->getQueryBuilder();
            $qb->update('facerecog_cluster_faces')
                ->set('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
                ->set('is_groupable', $qb->createNamedParameter($isGroupable, IQueryBuilder::PARAM_BOOL))
                ->where($qb->expr()->eq('face_id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($oldCluster, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logInfo('Updated face cluster assignment', [
                'faceId' => $faceId,
                'fromCluster' => $oldCluster,
                'toCluster' => $clusterId,
                'isGroupable' => $isGroupable,
                'sql' => $qb->getSQL(),
            ]);

        } catch (\InvalidArgumentException $e) {
            $this->logWarning('Invalid arguments for updateFace', [
                'faceId' => $faceId,
                'oldCluster' => $oldCluster,
                'clusterId' => $clusterId,
                'isGroupable' => $isGroupable,
                'sql' => $qb?->getSQL(),
                'exception' => $e,
            ]);
            throw $e;

        } catch (\Throwable $e) {
            $this->logError('Failed to update face assignment', [
                'faceId' => $faceId,
                'oldCluster' => $oldCluster,
                'newCluster' => $clusterId,
                'isGroupable' => $isGroupable,
                'sql' => $qb?->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Remove all faces associated with a given cluster (person).
     *
     * @param int $clusterId ID of the cluster
     *
     * @return void
     */
    public function removeAllFacesFromCluster(int $clusterId, ?IDBConnection $connection = null): void {
        $connection = $connection ?? $this->db;
        try {
            $qb = $connection->getQueryBuilder();
            $qb->delete('facerecog_cluster_faces')
                ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logInfo('Removed all face connections from cluster', [
                'clusterId' => $clusterId,
                'sql' => $qb->getSQL(),
            ]);

        } catch (\Throwable $e) {
            $this->logError('Failed to remove all faces from person', [
                'clusterId' => $clusterId,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Attach a single face to a cluster (person).
     *
     * @param int $clusterId ID of the cluster
     * @param int $faceId ID of the face
     * @param bool $isGroupable Whether the face can be grouped
     *
     * @return void
     */
    public function attachFaceToCluster(int $clusterId, int $faceId, bool $isGroupable = true, ?IDBConnection $connection = null): void {
        $connection = $connection ?? $this->db;
        try {
            $qb = $connection->getQueryBuilder();
            $qb->insert('facerecog_cluster_faces')
                ->values([
                    'face_id' => $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT),
                    'cluster_id' => $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT),
                    'is_groupable' => $qb->createNamedParameter($isGroupable, IQueryBuilder::PARAM_BOOL),
                ])
                ->executeStatement();

            $this->logInfo('Attached face to cluster', [
                'faceId' => $faceId,
                'clusterId' => $clusterId,
                'isGroupable' => $isGroupable,
                'sql' => $qb->getSQL(),
            ]);
        } catch (\Throwable $e) {
            // Get additional information about the face and cluster
            try {
                $faceSql = $connection->getQueryBuilder()
                    ->select('*')
                    ->from('facerecog_faces')
                    ->where('id = :id')
                    ->setParameter('id', $faceId);
                
                $clusterSql = $connection->getQueryBuilder()
                    ->select('*')
                    ->from('facerecog_clusters')
                    ->where('id = :id')
                    ->setParameter('id', $clusterId);

                $faceInfo = $faceSql->executeQuery()->fetch();
                $clusterInfo = $clusterSql->executeQuery()->fetch();

                $this->logError('Duplicated face-cluster association detected', [
                    'faceId' => $faceId,
                    'clusterId' => $clusterId,
                    'isGroupable' => $isGroupable,
                    'faceDetails' => $faceInfo ?: 'Face not found',
                    'clusterDetails' => $clusterInfo ?: 'Cluster not found',
                    'sql' => $qb->getSQL(),
                    'exception' => $e
                ]);
            } catch (\Throwable $queryError) {
                $this->logError('Failed to fetch additional details for duplicate entry', [
                    'faceId' => $faceId,
                    'clusterId' => $clusterId,
                    'isGroupable' => $isGroupable,
                    'sql' => $qb->getSQL(),
                    'exception' => $e,
                    'queryError' => $queryError->getMessage()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Remove a face from a cluster.
     *
     * If the cluster has only one face, it will rename/update the cluster.
     * Otherwise, it creates a new cluster for that face.
     *
     * @param int $clusterId ID of the cluster
     * @param int $faceId ID of the face
     * @param string|null $name Optional name to assign
     *
     * @return Person Updated cluster entity
     */
    public function detachFace(int $clusterId, int $faceId, ?string $name = null): Person {
        try {
            $faceCount = $this->countClusterFaces($clusterId);
            $this->logDebug('Cluster face count retrieved', [
                'clusterId' => $clusterId,
                'faceCount' => $faceCount,
            ]);

            if ($faceCount === 1) {
                // Single-face cluster: mark visible and rename
                $qb = $this->db->getQueryBuilder();
                $qb->update($this->getTableName())
                    ->set('is_visible', $qb->createNamedParameter(true))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)))
                    ->executeStatement();

                $this->updateClusterPersonConnection($clusterId, $name, $this->db);

                $this->logInfo('Single-face cluster updated', [
                    'clusterId' => $clusterId,
                    'name' => $name,
                    'sql' => $qb->getSQL(),
                ]);
            } else {
                // Multi-face cluster: create a new one for the detached face
                $qb = $this->db->getQueryBuilder();
                $qb->select('user')
                    ->from($this->getTableName())
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)));

                $oldPerson = $this->findEntity($qb);

                $qb = $this->db->getQueryBuilder();
                $qb->insert($this->getTableName())
                    ->values([
                        'user' => $qb->createNamedParameter($oldPerson->getUser(), IQueryBuilder::PARAM_STR),
                        'is_valid' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                        'last_generation_time' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
                        'linked_user' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                        'is_visible' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                    ])
                    ->executeStatement();

                $newClusterId = (int)$qb->getLastInsertId();

                $this->updateFace($faceId, $clusterId, $newClusterId, false);
                $this->updateClusterPersonConnection($newClusterId, $name, $this->db);

                $this->logInfo('Created new cluster for detached face', [
                    'oldClusterId' => $clusterId,
                    'newClusterId' => $newClusterId,
                    'faceId' => $faceId,
                    'name' => $name,
                    'sql' => $qb->getSQL(),
                ]);
            }

            // Return updated cluster entity
            $qb = $this->db->getQueryBuilder();
            $qb->select('c.id', 'c.user', 'p.name', 'c.is_visible', 'c.is_valid', 'c.last_generation_time', 'c.linked_user')
                ->from($this->getTableName(), 'c')
                ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
                ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
                ->where($qb->expr()->eq('c.id', $qb->createNamedParameter($clusterId)));

            $entity = $this->findEntity($qb);

            $this->logDebug('Returning updated cluster entity', [
                'clusterId' => $entity->getId(),
                'user' => $entity->getUser(),
                'name' => $entity->getName(),
                'sql' => $qb->getSQL(),
            ]);

            return $entity;

        } catch (\Throwable $e) {
            $this->logError('Failed to detach face from cluster', [
                'clusterId' => $clusterId,
                'faceId' => $faceId,
                'name' => $name,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
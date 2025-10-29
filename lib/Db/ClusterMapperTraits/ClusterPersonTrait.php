<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\IDBConnection;

use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
trait ClusterPersonTrait
{
    /**
     * Invalidates all persons associated with faces from a given image.
     *
     * @param int $imageId ID of the image
     * @param string $userId ID of the user
     *
     * @return void
     */
    public function invalidatePersons(int $imageId, string $userId): void {
        try {
            $this->logDebug('Start InvalidatePerson', [
                'imageId' => $imageId,
                'userId' => $userId
            ]);

            // Step 1: Find clusters to invalidate
            $subQb = $this->db->getQueryBuilder();
            $subQb->select('c.id')
                ->from($this->getTableName(), 'c')
                ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $subQb->expr()->eq('cf.cluster_id', 'c.id'))
                ->innerJoin('c', 'facerecog_faces', 'f', $subQb->expr()->eq('cf.face_id', 'f.id'))
                ->innerJoin('c', 'facerecog_images', 'i', $subQb->expr()->eq('f.image_id', 'i.id'))
                ->innerJoin('c', 'facerecog_user_images', 'ui', $subQb->expr()->eq('i.id', 'ui.image_id'))
                ->where($subQb->expr()->eq('f.image_id', $subQb->createParameter('image_id')))
                ->andWhere($subQb->expr()->eq('c.user', $subQb->createParameter('user_id')))
                ->setParameter('user_id', $userId)
                ->setParameter('image_id', $imageId);

            $clustersToInvalidate = $this->findEntities($subQb);

            // Step 2: Invalidate each cluster
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('is_valid', $qb->createParameter('is_valid'))
                ->where($qb->expr()->eq('id', $qb->createParameter('cluster_id')))
                ->setParameter('is_valid', false, IQueryBuilder::PARAM_BOOL);

            foreach ($clustersToInvalidate as $cluster) {
                $qb->setParameter('cluster_id', $cluster->getId(), IQueryBuilder::PARAM_INT)
                ->executeStatement();
            }

            $this->logInfo('Completed', [
                'imageId' => $imageId,
                'userId' => $userId,
                'invalidatedCount' => count($clustersToInvalidate)
            ]);

        } catch (\Throwable $e) {
            $this->logError('Failed', [
                'imageId' => $imageId,
                'userId' => $userId,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Reconciles current clusters with new clusters in the database.
     *
     * @param string $userId ID of the user
     * @param array $currentClusters Current clusters (clusterId => [faceIds])
     * @param array $newClusters New clusters (clusterId => [faceIds])
     *
     * @return array Summary of changes: ['added' => [], 'modified' => [], 'deleted' => []]
     */
    public function mergeClusterToDatabase(string $userId, array $currentClusters, array $newClusters): array {
        $this->db->beginTransaction();
        $currentDateTime = new \DateTimeImmutable();

        $countOfClusters = [
            'added' => [],
            'modified' => [],
            'deleted' => []
        ];

        try {
            $this->logInfo('Start merge clusters to database', [
                'userId' => $userId,
                'Count' => count($currentClusters),
                'newCount' => count($newClusters),
                'timestamp' => $currentDateTime->format('Y-m-d H:i:s'),
                'ClusterIds' => array_keys($currentClusters),
                'newClusterIds' => array_keys($newClusters)
            ]);

            // Step 1: Remove all old faces from current clusters
            foreach ($currentClusters as $oldPerson => $oldFaces) {
                $this->logDebug('Removing faces from person', [
                    'currentclusterId' => $oldPerson,
                    'faceCount' => count($oldFaces),
                    'faces' => $oldFaces
                ]);
                $this->removeAllFacesFromCluster($oldPerson, $this->db);
            }

            // Step 2: Add new clusters or update existing ones
            foreach ($newClusters as $newPerson => $newFaces) {
                if (array_key_exists($newPerson, $currentClusters)) {
                    $this->logDebug('Updating existing cluster', [
                        'clusterId' => $newPerson,
                        'faceCount' => count($newFaces)
                    ]);
                    
                    // Update existing cluster as valid
                    $qb = $this->db->getQueryBuilder();
                    $qb->update($this->getTableName())
                        ->set('is_valid', $qb->createParameter('is_valid'))
                        ->where($qb->expr()->eq('id', $qb->createNamedParameter($newPerson, IQueryBuilder::PARAM_INT)))
                        ->setParameter('is_valid', true, IQueryBuilder::PARAM_BOOL)
                        ->executeStatement();

                    $insertedClusterId = $newPerson;
                    $countOfClusters['modified'][] = $insertedClusterId;
                } else {
                    $this->logDebug('Creating new cluster', [
                        'userId' => $userId,
                        'faceCount' => count($newFaces)
                    ]);
                    
                    // Insert new cluster
                    $qb = $this->db->getQueryBuilder();
                    $qb->insert($this->getTableName())
                        ->values([
                            'user' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
                            'is_valid' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                            'last_generation_time' => $qb->createNamedParameter($currentDateTime, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                            'linked_user' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
                        ])
                        ->executeStatement();

                    $insertedClusterId = $qb->getLastInsertId();
                    $countOfClusters['added'][] = $insertedClusterId;
                    
                    $this->logDebug('New cluster created', [
                        'newClusterId' => $insertedClusterId
                    ]);
                }

                // Attach all faces to the cluster
                $this->logDebug('Attaching faces to cluster', [
                    'clusterId' => $insertedClusterId,
                    'faceCount' => count($newFaces),
                    'faces' => $newFaces
                ]);
                
                foreach ($newFaces as $newFace) {
                    $this->attachFaceToCluster($insertedClusterId, $newFace, true, $this->db);
                }
            }

            // Step 3: Delete orphaned clusters
            $this->logDebug('Deleting orphaned clusters', [
                'userId' => $userId
            ]);
            
            $countOfClusters['deleted'] = $this->deleteOrphaned($userId, $this->db);

            $this->db->commit();

            $this->logInfo('Finished merge', [
                'userId' => $userId,
                'added' => [
                    'count' => count($countOfClusters['added']),
                    'clusterIds' => $countOfClusters['added']
                ],
                'modified' => [
                    'count' => count($countOfClusters['modified']),
                    'clusterIds' => $countOfClusters['modified']
                ],
                'deleted' => [
                    'count' => count($countOfClusters['deleted']),
                    'clusterIds' => $countOfClusters['deleted']
                ],
                'duration' => microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]
            ]);

            return $countOfClusters;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logError('Failed to merge clusters', [
                'userId' => $userId,
                'exception' => $e,
                'currentClusters' => count($currentClusters),
                'newClusters' => count($newClusters)
            ]);
            throw $e;
        }
    }

    /**
     * Deletes a person (cluster) if it has no associated faces.
     *
     * @param int $clusterId Person/cluster ID to check and remove if empty
     *
     * @return void
     */
    public function removeIfEmpty(int $clusterId): void {
        try {
            // Subquery: check if cluster has any faces
            $sub = $this->db->getQueryBuilder();
            $sub->select('c.id')
                ->from($this->getTableName(), 'c')
                ->leftJoin('c', 'facerecog_cluster_faces', 'cf', $sub->expr()->eq('cf.cluster_id', 'c.id'))
                ->where($sub->expr()->eq('cf.cluster_id', $sub->createParameter('cluster_id')));

            $subSql = $sub->getSQL();

            // Main delete query
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->eq('id', $qb->createParameter('cluster_id')))
                ->andWhere('id NOT IN (' . $subSql . ')')
                ->setParameter('cluster_id', $clusterId, IQueryBuilder::PARAM_INT);

            $deleted = $qb->executeStatement();

            if ($deleted > 0) {
                $this->logInfo('Deleted empty cluster', [
                    'clusterId' => $clusterId,
                    'sql' => $qb->getSQL(),
                    'deleted' => $deleted
                ]);
            } else {
                $this->logDebug('Cluster not deleted (not empty or not found)', [
                    'clusterId' => $clusterId,
                    'sql' => $qb->getSQL()
                ]);
            }

        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logError('Database exception while checking/removing cluster', [
                'clusterId' => $clusterId,
                'sql' => $qb->getSQL(),
                'exception' => $e
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Unexpected error while removing empty cluster', [
                'clusterId' => $clusterId,
                'sql' => $qb->getSQL(),
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Mark the cluster as hidden or visible to the user.
     *
     * @param int $clusterId ID of the person/cluster
     * @param bool $visible Visibility of the person
     *
     * @return void
     */
    public function setVisibility(int $clusterId, bool $visible): void {
        try {
            // Update cluster visibility
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('is_visible', $qb->createNamedParameter($visible, IQueryBuilder::PARAM_BOOL))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));

            $updated = $qb->executeStatement();

            if ($updated === 0) {
                $this->logDebug('No cluster found for visibility update', [
                    'clusterId' => $clusterId,
                    'visible' => $visible,
                    'sql' => $qb->getSQL(),
                ]);
                return;
            }

            if (!$visible) {
                // Remove person connections if cluster is hidden
                $qb = $this->db->getQueryBuilder();
                $deletedConnections = $qb->delete('facerecog_person_clusters')
                    ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();

                $this->logInfo('Cluster hidden and person connections removed', [
                    'clusterId' => $clusterId,
                    'deletedConnections' => $deletedConnections,
                    'sql' => $qb->getSQL(),
                ]);
            } else {
                $this->logInfo('Cluster made visible', [
                    'clusterId' => $clusterId,
                    'sql' => $qb->getSQL(),
                ]);
            }
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logError('Database exception while setting cluster visibility', [
                'clusterId' => $clusterId,
                'visible' => $visible,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Unexpected error while updating cluster visibility', [
                'clusterId' => $clusterId,
                'visible' => $visible,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Handles cluster-Person connection based on name.
     *
     * @param int $clusterId ID of the cluster
     * @param string|null $personName Name of the person
     * @param IDBConnection|null $db Optional dbConnection
     *
     * @return void
     * @throws MultipleObjectsReturnedException
     */
    public function updateClusterPersonConnection(int $clusterId, ?string $personName, ?IDBConnection $db = null): void {
        $db ??= $this->db;

        $this->logDebug('Updating cluster-person connection', [
            'clusterId' => $clusterId,
            'personName' => $personName ?? 'NULL',
        ]);

        try {
            // Check existing connections
            $qb = $db->getQueryBuilder();
            $qb->select('*')
                ->from('facerecog_person_clusters')
                ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));

            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();

            if ($data !== false && count($data) > 0) {
                if (count($data) > 1) {
                    throw new MultipleObjectsReturnedException(
                        'Multiple connections found for cluster_id ' . $clusterId
                    );
                }

                // Remove existing connection
                $qb = $db->getQueryBuilder();
                $deleted = $qb->delete('facerecog_person_clusters')
                    ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
                    ->andWhere($qb->expr()->eq('person_id', $qb->createNamedParameter($data[0]['person_id'])))
                    ->executeStatement();

                $this->logInfo('Removed existing person connection', [
                    'clusterId' => $clusterId,
                    'personId' => $data[0]['person_id'],
                    'deletedRows' => $deleted,
                    'sql' => $qb->getSQL(),
                ]);

                // Remove orphaned persons
                $qb = $db->getQueryBuilder();
                $orphanedResult = $qb->select('p.id')
                    ->from('facerecog_persons', 'p')
                    ->leftJoin('p', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('p.id', 'pc.person_id'))
                    ->where($qb->expr()->isNull('pc.person_id'))
                    ->executeQuery();

                $orphanedPersons = $orphanedResult->fetchAll();
                $orphanedResult->closeCursor();

                foreach ($orphanedPersons as $person) {
                    $qb = $db->getQueryBuilder();
                    $qb->delete('facerecog_persons')
                        ->where($qb->expr()->eq('id', $qb->createNamedParameter($person['id'], IQueryBuilder::PARAM_INT)))
                        ->executeStatement();

                    $this->logInfo('Deleted orphaned person', [
                        'personId' => $person['id'],
                        'sql' => $qb->getSQL(),
                    ]);
                }
            }

            if ($personName !== null) {
                $personId = $this->insertPersonIfNotExists($personName, $db);

                $qb = $db->getQueryBuilder();
                $qb->insert('facerecog_person_clusters')
                    ->values([
                        'cluster_id' => $qb->createNamedParameter($clusterId),
                        'person_id' => $qb->createNamedParameter($personId)
                    ])
                    ->executeStatement();

                $this->logInfo('Attached person to cluster', [
                    'clusterId' => $clusterId,
                    'personId' => $personId,
                    'personName' => $personName,
                    'sql' => $qb->getSQL(),
                ]);
            } else {
                $this->logDebug('No personName provided; existing connection removed if any', [
                    'clusterId' => $clusterId,
                    'sql' => $qb->getSQL(),
                ]);
            }
        } catch (MultipleObjectsReturnedException $e) {
            $this->logError('Multiple objects returned when updating cluster-person connection', [
                'clusterId' => $clusterId,
                'personName' => $personName ?? 'NULL',
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logError('Database exception during cluster-person connection update', [
                'clusterId' => $clusterId,
                'personName' => $personName ?? 'NULL',
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Unexpected error during cluster-person connection update', [
                'clusterId' => $clusterId,
                'personName' => $personName ?? 'NULL',
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\IDBConnection;

use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;

//MTODO: Implement try-catches
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
     * @param array $currentClusters Current clusters (personId => [faceIds])
     * @param array $newClusters New clusters (personId => [faceIds])
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
            $this->logDebug('Start merge clusters to database', [
                'userId' => $userId,
                'currentCount' => count($currentClusters),
                'newCount' => count($newClusters)
            ]);

            // Step 1: Remove all old faces from current clusters
            foreach ($currentClusters as $oldPerson => $oldFaces) {
                $this->removeAllFacesFromPerson($oldPerson);
            }

            // Step 2: Add new clusters or update existing ones
            foreach ($newClusters as $newPerson => $newFaces) {
                if (array_key_exists($newPerson, $currentClusters)) {
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
                }

                // Attach all faces to the cluster
                foreach ($newFaces as $newFace) {
                    $this->attachFaceToPerson($insertedClusterId, $newFace);
                }
            }

            // Step 3: Delete orphaned clusters
            $countOfClusters['deleted'] = $this->deleteOrphaned($userId, $this->db);

            $this->db->commit();

            $this->logInfo('Finished merge', [
                'userId' => $userId,
                'added' => count($countOfClusters['added']),
                'modified' => count($countOfClusters['modified']),
                'deleted' => count($countOfClusters['deleted'])
            ]);

            return $countOfClusters;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logError('Failed', [
                'userId' => $userId,
                'exception' => $e
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
        $sub = $this->db->getQueryBuilder();
        $sub->select('c.id')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'facerecog_cluster_faces', 'cf', $sub->expr()->eq('cf.cluster_id', 'c.id'))
            ->where($sub->expr()->eq('cf.cluster_id', $sub->createParameter('cluster_id')));

        $sql = $sub->getSQL();

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createParameter('cluster_id')))
            ->andWhere('id NOT IN (' . $sql . ')')
            ->setParameter('cluster_id', $clusterId, IQueryBuilder::PARAM_INT)
            ->executeStatement();

        $this->logInfo('Checked and removed cluster if empty', [
            'clusterId' => $clusterId
        ]);
    }

    /**
     * Mark the cluster as hidden or visible to user.
     *
     * @param int $clusterId ID of the person/cluster
     * @param bool $visible Visibility of the person
     *
     * @return void
     */
    public function setVisibility(int $clusterId, bool $visible): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('is_visible', $qb->createNamedParameter($visible, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        if (!$visible) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('facerecog_person_clusters')
                ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logInfo('Cluster hidden and removed person connections', [
                'clusterId' => $clusterId
            ]);
        } else {
            $this->logInfo('Cluster made visible', [
                'clusterId' => $clusterId
            ]);
        }
    }

    /**
     * Handles cluster-Person connection based on name.
     *
     * @param int $clusterId ID of cluster
     * @param string|null $personName Name of the person
     * @param IDBConnection|null $db Optional dbConnection
     *
     * @return void
     * @throws MultipleObjectsReturnedException
     */
    public function updateClusterPersonConnection(int $clusterId, ?string $personName, ?IDBConnection $db = null): void {
        $db ??= $this->db;

        $this->logDebug('Update cluster person connection', [
            'clusterId' => $clusterId,
            'personName' => $personName ?? 'NULL'
        ]);

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
                    'Did not expect more than one result for cluster_id ' . $clusterId
                );
            }

            // Remove existing cluster-person connection
            $qb = $db->getQueryBuilder();
            $qb->delete('facerecog_person_clusters')
                ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
                ->andWhere($qb->expr()->eq('person_id', $qb->createNamedParameter($data[0]['person_id'])))
                ->executeStatement();

            $this->logInfo('Removed existing person connection', [
                'clusterId' => $clusterId,
                'personId' => $data[0]['person_id']
            ]);

            // Delete orphaned persons
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
                    'personId' => $person['id']
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
                'personName' => $personName
            ]);
        } else {
            $this->logDebug('No personName provided, connection removed if existed', [
                'clusterId' => $clusterId
            ]);
        }
    }

}
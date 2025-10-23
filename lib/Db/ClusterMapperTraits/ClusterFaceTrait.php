<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Person;

//MTODO: Implement try-catches
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
        if ($oldCluster === null && $clusterId === null) {
            throw new \InvalidArgumentException('No clusterId was given for face ID: ' . $faceId);
        }

        if ($oldCluster === null) {
            $this->attachFaceToPerson($clusterId, $faceId, $isGroupable);
            $this->logDebug('Attached face', [
                'faceId' => $faceId,
                'clusterId' => $clusterId,
                'isGroupable' => $isGroupable
            ]);
            return;
        }

        if ($clusterId === null) {
            $this->detachFace($oldCluster, $faceId);
            $this->logDebug('Detached face', [
                'faceId' => $faceId,
                'oldCluster' => $oldCluster
            ]);
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('facerecog_cluster_faces')
            ->set("cluster_id", $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
            ->set("is_groupable", $qb->createNamedParameter($isGroupable, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('face_id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($oldCluster, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $this->logInfo('Moved face', [
            'faceId' => $faceId,
            'fromCluster' => $oldCluster,
            'toCluster' => $clusterId,
            'isGroupable' => $isGroupable
        ]);
    }

    /**
     * Remove all faces associated with a given cluster (person).
     *
     * @param int $clusterId ID of the cluster
     *
     * @return void
     */
    public function removeAllFacesFromPerson(int $clusterId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('facerecog_cluster_faces')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
            ->executeStatement();

        $this->logInfo('Removed all face connections', [
            'clusterId' => $clusterId
        ]);
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
    public function attachFaceToPerson(int $clusterId, int $faceId, bool $isGroupable = true): void {
        try {
            $qb = $this->db->getQueryBuilder();
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
                'isGroupable' => $isGroupable
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Ignore duplicated keys exceptions
            $this->logError('Duplicated keys four, exception ignored, but ERROR logded', [
                'faceId' => $faceId,
                'clusterId' => $clusterId,
                'isGroupable' => $isGroupable,
                'exception' => $e
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to attach face to cluster', [
                'faceId' => $faceId,
                'clusterId' => $clusterId,
                'isGroupable' => $isGroupable,
                'exception' => $e
            ]);
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
        $this->logDebug('Detaching face', [
            'faceId' => $faceId,
            'clusterId' => $clusterId,
            'name' => $name
        ]);

        if ($this->countClusterFaces($clusterId) === 1) {
            // Single face: just rename/update the cluster
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('is_visible', $qb->createNamedParameter(true))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($clusterId)))
                ->executeStatement();

            $this->updateClusterPersonConnection($clusterId, $name, $this->db);

            $this->logDebug('Single-face cluster renamed/updated', [
                'clusterId' => $clusterId,
                'name' => $name
            ]);
        } else {
            // Multiple faces: create a new cluster for this face
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
                    'is_visible' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
                ])
                ->executeStatement();

            $newClusterId = $qb->getLastInsertId();

            // Move the face to the new cluster and mark as non-groupable
            $this->updateFace($faceId, $clusterId, $newClusterId, false);
            $this->updateClusterPersonConnection($newClusterId, $name, $this->db);

            $this->logInfo('Face moved to new cluster', [
                'faceId' => $faceId,
                'oldClusterId' => $clusterId,
                'newClusterId' => $newClusterId,
                'name' => $name
            ]);
        }

        // Return updated cluster entity
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.user', 'p.name', 'c.is_visible', 'c.is_valid', 'c.last_generation_time', 'c.linked_user')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'c.id'))
            ->leftJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
            ->where($qb->expr()->eq('c.id', $qb->createNamedParameter($clusterId)));

        return $this->findEntity($qb);
    }
}
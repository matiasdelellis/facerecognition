<?php
namespace OCA\FaceRecognition\Db\FaceMapperTraits;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Face;

trait ModificationTrait {
    /**
     * Removes all faces contained in one image.
     * Note that this is independent of any Model
     *
     * @param int $imageId Image for which to delete faces
     * @param IDBConnection|null $db Optional database connection to use
     * @return void
     */
    public function removeFromImage(int $imageId, ?IDBConnection $db = null): void {
        $qb = $db !== null ? $db->getQueryBuilder() : $this->db->getQueryBuilder();

        try {
            $qb->delete($this->getTableName())
                ->where($qb->expr()->eq('image_id', $qb->createParameter('image')))
                ->setParameter('image', $imageId)
                ->executeStatement();

            $this->logInfo('Removed all faces from image', [
                'imageId' => $imageId,
                'sql' => $qb->getSQL(),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Error removing faces from image', [
                'imageId' => $imageId,
                'sql' => $qb->getSQL(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    /**
     * Deletes all faces for a specific user and model
     *
     * @param string $userId User to delete faces for
     * @param int $modelId Model ID to delete faces for
     * @return void
     */
    public function deleteUserModel(string $userId, int $modelId): void {
        $sub = $this->db->getQueryBuilder();
        $sub->select($sub->expr()->literal('1'))
            ->from('facerecog_images', 'i')
            ->innerJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
            ->where($sub->expr()->eq('i.id', '*PREFIX*' . $this->getTableName() . '.image_id'))
            ->andWhere($sub->expr()->eq('ui.user', $sub->createParameter('user')))
            ->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')));

        $qb = $this->db->getQueryBuilder();
        try {
            $qb->delete($this->getTableName())
                ->where('EXISTS (' . $sub->getSQL() . ')')
                ->setParameter('user', $userId)
                ->setParameter('model', $modelId)
                ->executeStatement();

            $this->logInfo('Deleted faces for user and model', [
                'userId' => $userId,
                'modelId' => $modelId,
                'sql' => $qb->getSQL(),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Error deleting faces for user and model', [
                'userId' => $userId,
                'modelId' => $modelId,
                'sql' => $sub->getSQL(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Unsets the relation between faces and persons for a given user to reset clustering
     *
     * @param string $userId User for which to unset relations
     * @param int $model Model ID
     * @return void
     */
    public function unsetPersonsRelationForUser(string $userId, int $model): void {
        $sub = $this->db->getQueryBuilder();
        $sub->select('cf.cluster_id')
            ->from('facerecog_cluster_faces', 'cf')
            ->innerJoin('cf', $this->getTableName(), 'f', $sub->expr()->eq('cf.face_id', 'f.id'))
            ->innerJoin('cf', 'facerecog_images', 'i', $sub->expr()->eq('f.image_id', 'i.id'))
            ->innerJoin('cf', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
            ->innerJoin('cf', 'facerecog_clusters', 'c', $sub->expr()->eq('cf.cluster_id', 'c.id'))
            ->where($sub->expr()->eq('ui.user', $sub->createParameter('user')))
            ->andWhere($sub->expr()->eq('c.user', $sub->createParameter('user')))
            ->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')));

        $qb = $this->db->getQueryBuilder();
        try {
            $qb->delete('facerecog_cluster_faces')
                ->where('cluster_id IN (' . $sub->getSQL() . ')')
                ->setParameter('model', $model)
                ->setParameter('user', $userId)
                ->executeStatement();

            $this->logInfo('Unset person relations for user', [
                'userId' => $userId,
                'model' => $model,
                'sql' => $qb->getSQL(),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Error unsetting person relations for user', [
                'userId' => $userId,
                'model' => $model,
                'sql' => $qb->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Inserts one face into the database.
     *
     * @param Face $face Face to insert
     * @param IDBConnection|null $db Optional database connection to reuse
     * @return Face
     */
    public function insertFace(Face $face, ?IDBConnection $db = null): Face {
        $db = $db ?? $this->db;
        $qb = $db->getQueryBuilder();
        $insertPersonFaceConnection = $db->getQueryBuilder();

        try {
            $qb->insert($this->getTableName())
                ->values([
                    'image_id' => $qb->createNamedParameter($face->image),
                    'x' => $qb->createNamedParameter($face->x),
                    'y' => $qb->createNamedParameter($face->y),
                    'width' => $qb->createNamedParameter($face->width),
                    'height' => $qb->createNamedParameter($face->height),
                    'confidence' => $qb->createNamedParameter($face->confidence),
                    'landmarks' => $qb->createNamedParameter(json_encode($face->landmarks)),
                    'descriptor' => $qb->createNamedParameter(json_encode($face->descriptor)),
                    'creation_time' => $qb->createNamedParameter($face->creationTime, IQueryBuilder::PARAM_DATETIME_MUTABLE),
                ])
                ->executeStatement();

            $face->setId($qb->getLastInsertId());

            if ($face->person !== null) {
                $insertPersonFaceConnection->insert('facerecog_cluster_faces')
                    ->values([
                        'face_id' => $insertPersonFaceConnection->createNamedParameter($face->id),
                        'cluster_id' => $insertPersonFaceConnection->createNamedParameter($face->person)
                    ])
                    ->executeStatement();

                $this->logInfo('Inserted face with cluster association', [
                    'faceId' => $face->getId(),
                    'imageId' => $face->image,
                    'clusterId' => $face->person,
                    'sql' => $qb->getSQL(),
                    'personFaceSql' => $insertPersonFaceConnection->getSQL(),
                ]);
            } else {
                $this->logInfo('Inserted face without cluster association', [
                    'faceId' => $face->getId(),
                    'imageId' => $face->image,
                    'sql' => $qb->getSQL(),
                ]);
            }

            return $face;

        } catch (\Throwable $e) {
            $this->logError('Error inserting face', [
                'imageId' => $face->image,
                'faceData' => $face,
                'sql' => $qb->getSQL(),
                'personFaceSql' => $insertPersonFaceConnection->getSQL(),
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
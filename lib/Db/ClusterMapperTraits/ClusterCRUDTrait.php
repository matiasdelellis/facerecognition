<?php
namespace OCA\FaceRecognition\Db\ClusterMapperTraits;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\Entity;

//MTODO: Implement try-catches
trait ClusterCRUDTrait
{
    #[\Override]
    public function insert(Entity $entity): Entity {
        try {
            $properties = $entity->getUpdatedFields();

            $qb = $this->db->getQueryBuilder();
            $qb->insert($this->tableName);

            $hasName = false;

            foreach ($properties as $property => $updated) {
                $column = $entity->propertyToColumn($property);
                if ($column === "name") {
                    $hasName = true;
                    continue; // handle 'name' after insert
                }

                $getter = 'get' . ucfirst($property);
                $value = $entity->$getter();
                $type = $this->getParameterTypeForProperty($entity, $property);

                $qb->setValue($column, $qb->createNamedParameter($value, $type));
            }

            $qb->executeStatement();

            $entity->setId($qb->getLastInsertId());

            if ($hasName) {
                $this->updateClusterPersonConnection($entity->getId(), $entity->getName());
            }

            $this->logInfo('Inserted cluster', [
                'clusterId' => $entity->getId(),
                'name'      => $entity->getName(),
                'user'      => $entity->getUser(),
            ]);

            return $entity;

        } catch (\Throwable $e) {
            $this->logError('Failed to insert entity', [
                'exception' => $e,
                'entity'    => $entity,
            ]);
            throw $e;
        }
    }

    /**
     * Insert Person name if not exists.
     *
     * @param string $personName Name of the person
     * @param IDBConnection|null $db Optional dbConnection
     *
     * @return int ID of the person
     */
    public function insertPersonIfNotExists(string $personName, ?IDBConnection $db = null): int {
        $db ??= $this->db;

        $qb = $db->getQueryBuilder();
        $qb->select('id')
            ->from('facerecog_persons')
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($personName)));

        $result = $qb->executeQuery();
        $data = $result->fetch();
        $result->closeCursor();

        if ($data !== false) {
            $this->logDebug('Person already exists', [
                'personName' => $personName,
                'personId' => $data['id']
            ]);
            return (int)$data['id'];
        }

        $qb = $db->getQueryBuilder();
        $qb->insert('facerecog_persons')
            ->values([
                'name' => $qb->createNamedParameter($personName)
            ])
            ->executeStatement();

        $newId = (int)$qb->getLastInsertId();

        $this->logInfo('Inserted new person', [
            'personName' => $personName,
            'personId' => $newId
        ]);

        return $newId;
    }
    
    #[\Override]
    public function update(Entity $entity): Entity {
        try {
            $properties = $entity->getUpdatedFields();
            if (count($properties) === 0) {
                $this->logDebug('No fields updated', [
                    'clusterId' => $entity->getId()
                ]);
                return $entity;
            }

            $id = $entity->getId();
            if ($id === null) {
                throw new \InvalidArgumentException('Entity which should be updated has no id');
            }

            unset($properties['id']); // do not update ID field

            $qb = $this->db->getQueryBuilder();
            $qb->update($this->tableName);
            $isExecutable = false;

            foreach ($properties as $property => $updated) {
                $column = $entity->propertyToColumn($property);
                $getter = 'get' . ucfirst($property);
                $value = $entity->$getter();

                if ($column === "name") {
                    $this->updateClusterPersonConnection($id, $value);
                    continue; // name is handled separately
                }

                $type = $this->getParameterTypeForProperty($entity, $property);
                $qb->set($column, $qb->createNamedParameter($value, $type));
                $isExecutable = true;
            }

            $idType = $this->getParameterTypeForProperty($entity, 'id');
            $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, $idType)));

            if ($isExecutable) {
                $qb->executeStatement();
                $this->logInfo('Updated cluster', [
                    'clusterId'     => $id,
                    'updatedFields' => array_keys($properties)
                ]);
            } else {
                $this->logDebug('Nothing to update (only name change handled separately)', [
                    'clusterId' => $id
                ]);
            }

            return $entity;

        } catch (\Throwable $e) {
            $this->logError('Failed to update entity', [
                'exception' => $e,
                'entity'    => $entity
            ]);
            throw $e;
        }
    }

    /**
     * Deletes all persons (clusters) that have no faces associated with them.
     *
     * @param string $userId ID of user for which we are deleting orphaned persons
     * @param IDBConnection|null $db Optional database connection
     *
     * @return int[] List of deleted person/cluster IDs
     */
    public function deleteOrphaned(string $userId, ?IDBConnection $db = null): array {
        $db ??= $this->db;

        // Find orphaned clusters
        $qb = $db->getQueryBuilder();
        $qb->select('c.id')
            ->from($this->getTableName(), 'c')
            ->leftJoin('c', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('c.id', 'cf.cluster_id'))
            ->where($qb->expr()->eq('c.user', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNull('cf.face_id'));

        $orphanedPersons = $this->findEntities($qb);
        $this->logDebug('Found ' . count($orphanedPersons) . ' orphaned clusters', [
            'userId' => $userId
        ]);

        // Delete them one by one
        $deletedIds = [];
        foreach ($orphanedPersons as $person) {
            $qb = $db->getQueryBuilder();
            $deletedIds[] = $person->getId();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($person->getId(), IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->logDebug('Deleted orphaned cluster', [
                'clusterId' => $person->getId(),
                'userId' => $userId
            ]);
        }

        $this->logInfo('Deleted total orphaned clusters', [
            'count' => count($deletedIds),
            'userId' => $userId
        ]);

        return $deletedIds;
    }

    /**
     * Deletes all persons for a specific user.
     *
     * @param string $userId ID of the user whose persons should be deleted
     *
     * @return void
     */
    public function deleteUserPersons(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->executeStatement();

        $this->logInfo('Deleted persons for user', [
            'userId' => $userId
        ]);

        // All person-face connections should be automatically deleted via foreign key
    }

    /**
     * Deletes all persons for a specific user and model.
     *
     * @param string $userId ID of the user
     * @param int $modelId ID of the model
     *
     * @return void
     */
    public function deleteUserModel(string $userId, int $modelId): void {
        // TODO: Make it atomic (wrap in transaction)
        $persons = $this->findAll($userId, $modelId);

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createParameter('person_id')));

        foreach ($persons as $person) {
            $qb->setParameter('person_id', $person->getId(), IQueryBuilder::PARAM_INT)
                ->executeStatement();
        }

        $this->logInfo('Deleted persons for user', [
            'userId' => $userId,
            'modelId' => $modelId,
            'deletedCount' => count($persons)
        ]);
    }

}
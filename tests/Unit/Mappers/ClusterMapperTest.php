<?php

/**
 * @copyright Copyright (c) 2024, Mate Zsolya <zsolyamate@gmail.com>
 *
 * @author Mate Zsolya <zsolyamate@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Tests\Unit\Mappers;

use DateTime;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OC\DB\Exceptions\DbalException;

use OCA\FaceRecognition\Tests\Unit\UnitBaseTestCase;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

#[CoversClass(ClusterMapper::class)]
#[UsesClass(Person::class)]
class ClusterMapperTest extends UnitBaseTestCase
{
    /** @var ClusterMapper test instance*/
    private static $clusterMapper;

    /**
     * {@inheritDoc}
     */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
        self::$clusterMapper = new ClusterMapper(self::$dbConnection, self::$logger);
	}

    public function test_Update_notUpdated(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->resetUpdatedFields();

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_user(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setUser('user2');

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user2', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user2', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_changePerson(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setName('Dummy');

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Dummy', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_addToPerson(): void
    {
        $cluster =self::$clusterMapper->find('user1', 3);
        $cluster->setName('Dummy');

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 3);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(3, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Dummy', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 11:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_removeFromPerson(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setName(null);

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertNull($cluster->getName());
        $this->assertNull($cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_LinkedUser(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setLinkedUser('TestUser1');

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals('TestUser1', $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_IsVisible(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setIsVisible(false);

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(false, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_IsValid(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setIsValid(false);

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(false, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_LastGenerationTime(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setLastGenerationTime(DateTime::createFromFormat('Y-m-d H:i:s', '2020-01-01 00:00:00'));

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2020-01-01 00:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_IdNotUpdated(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setId(1000);

        //Act
        $cluster =self::$clusterMapper->update($cluster);

        //Assert
        $cluster =self::$clusterMapper->find('user1', 1);
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Update_IdIsNull_ExpectException(): void
    {
        $cluster =self::$clusterMapper->find('user1', 1);
        $cluster->setId(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity which should be updated has no id');

        //Act
        $cluster =self::$clusterMapper->update($cluster);
    }

    public function test_Find_nameExists(): void
    {
        //Act
        $cluster =self::$clusterMapper->find('user1', 1);

        //Assert
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(1, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals('Alice', $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(true, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:00:00'), $cluster->getLastGenerationTime());
    }

    public function test_Find_noNameExists(): void
    {
        //Act
        $cluster =self::$clusterMapper->find('user1', 5);

        //Assert
        $this->assertNotNull($cluster);
        $this->assertInstanceOf(Person::class, $cluster);
        $this->assertEquals(5, $cluster->getId());
        $this->assertEquals('user1', $cluster->getUser());
        $this->assertEquals(null, $cluster->getName());
        $this->assertEquals(null, $cluster->getLinkedUser());
        $this->assertEquals(false, $cluster->getIsVisible());
        $this->assertEquals(true, $cluster->getIsValid());
        $this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 11:01:00'), $cluster->getLastGenerationTime());
    }

    public function test_Find_noneExisting(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Did expect one result but found none when executing: query "SELECT `c`.`id`, `user`, `p`.`name`, `is_visible`, `is_valid`, `last_generation_time`, `linked_user` FROM `*PREFIX*facerecog_clusters` `c` LEFT JOIN `*PREFIX*facerecog_person_clusters` `pc` ON `pc`.`cluster_id` = `c`.`id` LEFT JOIN `*PREFIX*facerecog_persons` `p` ON (`pc`.`person_id` = `p`.`id`) AND (`pc`.`cluster_id` IS NOT NULL) WHERE (`c`.`id` = :dcValue1) AND (`c`.`user` = :dcValue2)"');

        //Act
        $cluster =self::$clusterMapper->find('user1', 8);

        //Assert
        $this->assertNull($cluster);
    }

    #[DataProviderExternal(PersonDataProvider::class, 'findByName_Provider')]
    public function test_FindByName(string $userId, int $modelId, string $personName, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findByName($userId, $modelId, $personName);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertEquals($userId, $cluster->getUser());
                $this->assertEquals($personName, $cluster->getName());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'getPersonsByFlagsWithoutName_Provider')]
    public function test_getPersonsByFlagsWithoutName(string $userId, int $modelId, bool $isValid, bool $isVisible, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->getPersonsByFlagsWithoutName($userId, $modelId, $isValid, $isVisible);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertEquals($userId, $cluster->getUser());
                $this->assertEquals($isValid, $cluster->getIsValid());
                $this->assertEquals($isVisible, $cluster->getIsVisible());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'findIgnored_Provider')]
    public function test_findIgnored(string $userId, int $modelId, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findIgnored($userId, $modelId);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertEquals($userId, $cluster->getUser());
                $this->assertEquals(true, $cluster->getIsValid());
                $this->assertEquals(false, $cluster->getIsVisible());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'findUnassigned_Provider')]
    public function test_findUnassigned(string $userId, int $modelId, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findUnassigned($userId, $modelId);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertEquals($userId, $cluster->getUser());
                $this->assertEquals(true, $cluster->getIsValid());
                $this->assertEquals(true, $cluster->getIsVisible());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'findAll_Provider')]
    public function test_findAll(string $userId, int $modelId, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findAll($userId, $modelId);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertEquals($userId, $cluster->getUser());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'findDistinctNames_Provider')]
    public function test_findDistinctNames(string $userId, int $modelId, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findDistinctNames($userId, $modelId);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertNotNull($cluster->getName());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'findDistinctNamesSelected_Provider')]
    public function test_findDistinctNamesSelected(string $userId, int $modelId, string $faceName, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findDistinctNamesSelected($userId, $modelId, $faceName);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertNotNull($cluster->getName());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'findPersonsLike_Provider')]
    public function test_findPersonsLike(string $userId, int $modelId, string $faceName, ?int $offset, ?int $limit, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->findPersonsLike($userId, $modelId, $faceName, $offset, $limit);

        //Assert
        $this->assertNotNull($people);
        $this->assertIsArray($people);
        $this->assertContainsOnlyInstancesOf(Person::class, $people);
        $this->assertCount($expectedCount, $people);
        if ($expectedCount > 0) {
            foreach ($people as $cluster) {
                $this->assertNotNull($cluster->getName());
            }
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'countPersons_Provider')]
    public function test_countPersons(string $userId, int $modelId, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->countPersons($userId, $modelId);

        //Assert
        $this->assertNotNull($people);
        $this->assertEquals($expectedCount, $people);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'countClusters_Provider')]
    public function test_countClusters(string $userId, int $modelId, bool $onlyInvalid, int $expectedCount): void
    {
        //Act
        $people =self::$clusterMapper->countClusters($userId, $modelId, $onlyInvalid);

        //Assert
        $this->assertNotNull($people);
        $this->assertEquals($expectedCount, $people);
    }

    //not possible to have more than 1000 face on one picture therefore not needed to test for 
    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'invalidatePersons_Provider')]
    public function test_invalidatePersons(int $imageId, string $user, int $clustersCount): void
    {
        //Act
       self::$clusterMapper->invalidatePersons($imageId, $user);

        //Assert
        $sub = self::$dbConnection->getQueryBuilder();
        $query = $sub->select('c.id', 'c.user','c.is_valid')
            ->from('facerecog_clusters', 'c')
            ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $sub->expr()->eq('cf.cluster_id', 'c.id'))
            ->innerJoin('c', 'facerecog_faces', 'f', $sub->expr()->eq('cf.face_id', 'f.id'))
            ->innerJoin('c', 'facerecog_images', 'i', $sub->expr()->eq('f.image_id', 'i.id'))
            ->Where($sub->expr()->eq('f.image_id', $sub->createParameter('image_id')))
            ->setParameter('image_id', $imageId)
            ->groupBy('c.id');
        $sqlResult = $query->executeQuery();
        $modifiedValidClusters = $sqlResult->fetchAll();
        $sqlResult->closeCursor();
        foreach ($modifiedValidClusters as $row)
        {
            if ($row['user']== $user)
            {
                $this->assertEquals(0, $row['is_valid']);
            }
            else {
                $this->assertEquals(1, $row['is_valid']);
            }
        }
        $this->assertCount($clustersCount, $modifiedValidClusters);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'deleteUserPersons_Provider')]
    public function test_deleteUserPersons(string $userId): void
    {
        //Act
       self::$clusterMapper->deleteUserPersons($userId);

        //Assert
        $sub = self::$dbConnection->getQueryBuilder();
        $query = $sub->select('c.id')
            ->from('facerecog_clusters', 'c')
            ->Where($sub->expr()->eq('c.user', $sub->createParameter('user')))
            ->setParameter('user', $userId, IQueryBuilder::PARAM_STR);
        $sqlResult = $query->executeQuery();
        $modifiedValidClusters = $sqlResult->fetchAll();
        $sqlResult->closeCursor();
        $this->assertCount(0, $modifiedValidClusters);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'deleteUserModel_Provider')]
    public function test_deleteUserModel(string $userId, int $modelId): void
    {
        //Act
       self::$clusterMapper->deleteUserModel($userId, $modelId);

        //Assert
        $sub = self::$dbConnection->getQueryBuilder();
        $query = $sub->select('c.id')
            ->from('facerecog_clusters', 'c')
            ->innerJoin('c', 'facerecog_cluster_faces', 'cf', $sub->expr()->eq('cf.cluster_id', 'c.id'))
            ->innerJoin('c', 'facerecog_faces', 'f', $sub->expr()->eq('cf.face_id', 'f.id'))
            ->innerJoin('c', 'facerecog_images', 'i', $sub->expr()->eq('f.image_id', 'i.id'))
            ->Where($sub->expr()->eq('c.user', $sub->createParameter('user')))
            ->andWhere($sub->expr()->eq('i.model', $sub->createParameter('model')))
            ->setParameter('user', $userId, IQueryBuilder::PARAM_STR)
            ->setParameter('model', $modelId, IQueryBuilder::PARAM_INT);
        $sqlResult = $query->executeQuery();
        $modifiedValidClusters = $sqlResult->fetchAll();
        $sqlResult->closeCursor();
        $this->assertCount(0, $modifiedValidClusters);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'removeIfEmpty_Provider')]
    public function test_removeIfEmpty(int $clusterId, bool $isDeleted): void
    {
        //Act
       self::$clusterMapper->removeIfEmpty($clusterId);

        //Assert
        $sub = self::$dbConnection->getQueryBuilder();
        $query = $sub->select('c.id')
            ->from('facerecog_clusters', 'c')
            ->Where($sub->expr()->eq('c.id', $sub->createParameter('id')))
            ->setParameter('id', $clusterId, IQueryBuilder::PARAM_INT);
        $sqlResult = $query->executeQuery();
        $modifiedValidClusters = $sqlResult->fetchAll();
        $sqlResult->closeCursor();
        if ($isDeleted) {
            $this->assertCount(0, $modifiedValidClusters);
        } else {
            $this->assertCount(1, $modifiedValidClusters);
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'deleteOrphaned_Provider')]
    public function test_deleteOrphaned(string $userId, int $expected): void
    {
        //Act
        $deletedEntries =self::$clusterMapper->deleteOrphaned($userId);

        //Assert
        $this->assertEquals($expected, count($deletedEntries));
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'deleteOrphaned_Provider')]
    public function test_deleteOrphaned_withDB(string $userId, int $expected): void
    {
        //Act
        $deletedEntries =self::$clusterMapper->deleteOrphaned($userId, self::$dbConnection);

        //Assert
        $this->assertEquals($expected, count($deletedEntries));
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'setVisibility_Provider')]
    public function test_setVisibility(int $clusterId, bool $visible): void
    {
        //Act
       self::$clusterMapper->setVisibility($clusterId, $visible);

        //Assert
        $sub = self::$dbConnection->getQueryBuilder();
        $query = $sub->select('c.id', 'p.name', 'is_visible')
            ->from('facerecog_clusters', 'c')
            ->leftJoin('c', 'facerecog_person_clusters', 'pc', $sub->expr()->eq('pc.cluster_id', 'c.id'))
            ->leftJoin('c', 'facerecog_persons', 'p', $sub->expr()->eq('pc.person_id', 'p.id'))
            ->Where($sub->expr()->eq('c.id', $sub->createParameter('id')))
            ->setParameter('id', $clusterId, IQueryBuilder::PARAM_INT);
        $sqlResult = $query->executeQuery();
        $modifiedValidClusters = $sqlResult->fetch();
        $sqlResult->closeCursor();
        if ($visible) {
            $this->assertEquals($clusterId, $modifiedValidClusters['id']);
            $this->assertEquals(true, $modifiedValidClusters['is_visible']);
        } else {
            $this->assertEquals($clusterId, $modifiedValidClusters['id']);
            $this->assertEquals(null, $modifiedValidClusters['name']);
            $this->assertEquals(false, $modifiedValidClusters['is_visible']);
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'detachFace_Provider')]
    public function test_detachFace(int $clusterId, int $faceId, ?string $name): void
    {
        //Act
        $cluster =self::$clusterMapper->detachFace($clusterId, $faceId, $name);

        //Assert
        $this->assertNotNull($cluster);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'insertPersonIfNotExists_Provider')]
    public function test_insertPersonIfNotExists(string $personName, int $expectedId): void
    {
        //Act
        $personId =self::$clusterMapper->insertPersonIfNotExists($personName);

        //Assert

        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('*')
            ->from('facerecog_persons')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($personId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        $this->assertNotNull($personId);
        $this->assertGreaterThanOrEqual($expectedId, $personId);
        $this->assertNotFalse($data);
        $this->assertCount(1, $data);
        $this->assertGreaterThanOrEqual($expectedId, $data[0]['id']);
        $this->assertEquals($personName, $data[0]['name']);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'insertPersonIfNotExists_Provider')]
    public function test_insertPersonIfNotExists_withDb(string $personName, int $expectedId): void
    {
        //Act
        $personId =self::$clusterMapper->insertPersonIfNotExists($personName, self::$dbConnection);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('facerecog_persons')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($personId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        $this->assertNotNull($personId);
        $this->assertGreaterThanOrEqual($expectedId, $personId);
        $this->assertNotFalse($data);
        $this->assertCount(1, $data);
        $this->assertGreaterThanOrEqual($expectedId, $data[0]['id']);
        $this->assertEquals($personName, $data[0]['name']);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'updateClusterPersonConnection_Provider')]
    public function test_updateClusterPersonConnections(int $clusterId, ?string $personName, int $expectedId, int $expectedpersonCount): void
    {
        //Act
       self::$clusterMapper->updateClusterPersonConnection($clusterId, $personName);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'person_id')
            ->from('facerecog_person_clusters')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        if ($personName !== null) {
            $this->assertNotFalse($data);
            $this->assertCount(1, $data);
            $this->assertGreaterThanOrEqual($expectedId, $data[0]['person_id']);
            $this->assertEquals($clusterId, $data[0]['cluster_id']);
            $qb = self::$dbConnection->getQueryBuilder();
            $qb->select('id', 'name')
                ->from('facerecog_persons')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($data[0]['person_id'])));
            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();
            $this->assertEquals($personName, $data[0]['name']);
        } else {
            $this->assertEmpty($data);
        }
        
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_persons');
        $result = $qb->executeQuery();
        $data = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();
        $this->assertEquals($expectedpersonCount, (int)$data[0]);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'updateClusterPersonConnection_Provider')]
    public function test_updateClusterPersonConnections_withDb(int $clusterId, ?string $personName, int $expectedId, int $expectedpersonCount): void
    {
        //Act
       self::$clusterMapper->updateClusterPersonConnection($clusterId, $personName, self::$dbConnection);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'person_id')
            ->from('facerecog_person_clusters')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        if ($personName !== null) {
            $this->assertNotFalse($data);
            $this->assertCount(1, $data);
            $this->assertGreaterThanOrEqual($expectedId, $data[0]['person_id']);
            $this->assertEquals($clusterId, $data[0]['cluster_id']);
            $qb = self::$dbConnection->getQueryBuilder();
            $qb->select('id', 'name')
                ->from('facerecog_persons')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($data[0]['person_id'])));
            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();
            $this->assertEquals($personName, $data[0]['name']);
        } else {
            $this->assertEmpty($data);
        }
        
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_persons');
        $result = $qb->executeQuery();
        $data = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();
        $this->assertEquals($expectedpersonCount, (int)$data[0]);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'updateClusterPersonConnection_error_Provider')]
    public function test_updateClusterPersonConnections_error(int $clusterId, ?string $personName, int $expectedId, int $expectedpersonCount): void
    {
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->insert('facerecog_person_clusters')
            ->values(
                [
                    'cluster_id' => $qb->createNamedParameter($clusterId),
                    'person_id' =>  $qb->createNamedParameter(2)
                ]
            )
            ->executeStatement();

        $this->expectException(MultipleObjectsReturnedException::class);
        $this->expectExceptionMessageMatches("/^Multiple connections found for cluster_id [0-9]+/");

        //Act
       self::$clusterMapper->updateClusterPersonConnection($clusterId, $personName);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'person_id')
            ->from('facerecog_person_clusters')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        if ($personName !== null) {
            $this->assertNotFalse($data);
            $this->assertCount(1, $data);
            $this->assertGreaterThanOrEqual($expectedId, $data[0]['person_id']);
            $this->assertEquals($clusterId, $data[0]['cluster_id']);
            $qb = self::$dbConnection->getQueryBuilder();
            $qb->select('id', 'name')
                ->from('facerecog_persons')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($data[0]['person_id'])));
            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();
            $this->assertEquals($personName, $data[0]['name']);
        } else {
            $this->assertEmpty($data);
        }
        
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_persons');
        $result = $qb->executeQuery();
        $data = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();
        $this->assertEquals($expectedpersonCount, (int)$data[0]);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'updateClusterPersonConnection_error_Provider')]
    public function test_updateClusterPersonConnections_withDb_error(int $clusterId, ?string $personName, int $expectedId, int $expectedpersonCount): void
    {
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->insert('facerecog_person_clusters')
            ->values(
                [
                    'cluster_id' => $qb->createNamedParameter($clusterId),
                    'person_id' =>  $qb->createNamedParameter(2)
                ]
            )
            ->executeStatement();

        $this->expectException(MultipleObjectsReturnedException::class);
        $this->expectExceptionMessageMatches("/^Multiple connections found for cluster_id [0-9]+/");

        //Act
       self::$clusterMapper->updateClusterPersonConnection($clusterId, $personName, self::$dbConnection);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'person_id')
            ->from('facerecog_person_clusters')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        if ($personName !== null) {
            $this->assertNotFalse($data);
            $this->assertCount(1, $data);
            $this->assertGreaterThanOrEqual($expectedId, $data[0]['person_id']);
            $this->assertEquals($clusterId, $data[0]['cluster_id']);
            $qb = self::$dbConnection->getQueryBuilder();
            $qb->select('id', 'name')
                ->from('facerecog_persons')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($data[0]['person_id'])));
            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();
            $this->assertEquals($personName, $data[0]['name']);
        } else {
            $this->assertEmpty($data);
        }
        
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_persons');
        $result = $qb->executeQuery();
        $data = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();
        $this->assertEquals($expectedpersonCount, (int)$data[0]);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'countClusterFaces_Provider')]
    public function test_countClusterFaces(int $clusterId, int $expectedcount): void
    {
        //Act
        $faceCount =self::$clusterMapper->countClusterFaces($clusterId);

        //Assert
        $this->assertNotNull($faceCount);
        $this->assertEquals($expectedcount, $faceCount);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'updateFace_Provider')]
    public function test_updateFace(int $faceId, ?int $oldClusterId, ?int $clusterId, bool $isGroupable, bool $expectedError): void
    {
        if ($expectedError) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches("/^No clusterId was given for face ID: [0-9]+/");
        }
        //Act
       self::$clusterMapper->updateFace($faceId, $oldClusterId, $clusterId, $isGroupable);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'face_id', 'is_groupable')
            ->from('facerecog_cluster_faces')
            ->where($qb->expr()->eq('face_id', $qb->createNamedParameter($faceId)))
            ->andWhere($qb->expr()->eq('is_groupable', $qb->createNamedParameter($isGroupable)));
        if ($clusterId !== null) {
            $qb->andwhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();

            $this->assertNotFalse($data);
            $this->assertCount(1, $data);
        }
        if ($oldClusterId !== null) {
            $qb->andwhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($oldClusterId)));
            $result = $qb->executeQuery();
            $data = $result->fetchAll();
            $result->closeCursor();

            $this->assertEmpty($data);
        }
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'removeAllFacesFromCluster_Provider')]
    public function test_removeAllFacesFromClusterint(int $clusterId): void
    {
        //Act
       self::$clusterMapper->removeAllFacesFromCluster($clusterId);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'face_id')
            ->from('facerecog_cluster_faces')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();
        $this->assertEmpty($data);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'attachFaceToCluster_Provider')]
    public function test_attachFaceToCluster(int $clusterId, int $faceId, bool $expectError, ?string $message): void
    {
        if ($expectError) {
            $this->expectException(\OC\DB\Exceptions\DbalException::class);
            $this->expectExceptionMessage($message);
        }
        //Act
       self::$clusterMapper->attachFaceToCluster($clusterId, $faceId);

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select('cluster_id', 'face_id')
            ->from('facerecog_cluster_faces')
            ->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId)))
            ->andwhere($qb->expr()->eq('face_id', $qb->createNamedParameter($faceId)));
        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();
        $this->assertIsArray($data);
        $this->greaterThanOrEqual(1, $data);
    }

    #[DataProviderExternal(className: PersonDataProvider::class, methodName: 'mergeClusterToDatabase_Provider')]
    public function test_mergeClusterToDatabase(string $userId, array $currentClusters, array $newClusters, int $modifiedCount, int $addedCount, int $deletedCount): void
    {
        $initialOrphandClusters = 1;
        $addedConnection = 0;
        foreach ($newClusters as $newCluster) {
            $addedConnection += count($newCluster);
        }
        foreach ($currentClusters as $currentCluster) {
            $addedConnection -= count($currentCluster);
        }
        $initConnectionCount = 20 + $addedConnection;
        $initClusterCount = 14  - $initialOrphandClusters - $deletedCount + $addedCount;
        $deletedCount += $initialOrphandClusters;

        //Act
        $countOfActions =self::$clusterMapper->mergeClusterToDatabase($userId, $currentClusters, $newClusters);

        //Assert

        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_clusters');
        $result = $qb->executeQuery();
        $data = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();

        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_cluster_faces');
        $result = $qb->executeQuery();
        $dataconnection = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();

        $this->assertEquals($modifiedCount, count($countOfActions["modified"]), "Modification");
        $this->assertEquals($addedCount, count($countOfActions["added"]), "Creation");
        $this->assertEquals($deletedCount, count($countOfActions["deleted"]), "Deletion");
        $this->assertEquals($initClusterCount, (int)$data[0]);
        $this->assertEquals($initConnectionCount, (int)$dataconnection[0]);
    }

    public function test_mergeClusterToDatabase_withException(): void
    {
        $initConnectionCount = 20;
        $initClusterCount = 14;

        $this->expectException(DbalException::class);

        //Act
        $countOfActions = self::$clusterMapper->mergeClusterToDatabase("user1", array(3 => [3]), array(3 => [10000]));

        //Assert
        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_clusters');
        $result = $qb->executeQuery();
        $data = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();

        $qb = self::$dbConnection->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('facerecog_cluster_faces');
        $result = $qb->executeQuery();
        $dataconnection = $result->fetch(\PDO::FETCH_NUM);
        $result->closeCursor();

        $this->assertNull($countOfActions);
        $this->assertEquals($initClusterCount, (int)$data[0]);
        $this->assertEquals($initConnectionCount, (int)$dataconnection[0]);
    }

    /**
     * {@inheritDoc}
     */
    public function tearDown(): void
    {
        parent::tearDown();
    }

	public static function tearDownAfterClass(): void {
		self::$clusterMapper = null;
		parent::tearDownAfterClass();
	}
}

class PersonDataProvider
{
    public static function findByName_Provider(): array
    {
        return [
            ['user1', 1, 'Alice', 1],
            ['user1', 3, 'Alice', 0],
            ['user3', 1, 'Alice', 0],
            ['user1', 1, 'Dummy', 0],
            ['user2', 1, 'Alice', 0],
            ['user2', 2, 'Bob', 2],
        ];
    }

    public static function getPersonsByFlagsWithoutName_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, false, false, 0],
            ['user1', 1, false, true,  0],
            ['user1', 1, true, false,  4],
            ['user1', 1, true, true,   0],
            //nonexisting model
            ['user1', 3, false, false, 0],
            ['user1', 3, false, true,  0],
            ['user1', 3, true, false,  0],
            ['user1', 3, true, true,   0],
            //nonexisting user
            ['user3', 1, false, false, 0],
            ['user3', 1, false, true,  0],
            ['user3', 1, true, false,  0],
            ['user3', 1, true, true,   0],
            //User has mixed models
            ['user2', 1, false, false, 0],
            ['user2', 1, false, true,  0],
            ['user2', 1, true, false,  0],
            ['user2', 1, true, true,   2],

            ['user2', 2, false, false, 0],
            ['user2', 2, false, true,  1],
            ['user2', 2, true, false,  0],
            ['user2', 2, true, true,   0],
        ];
    }

    public static function findIgnored_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 4],
            //nonexisting model
            ['user1', 3, 0],
            //nonexisting user
            ['user3', 1, 0],
            //User has mixed models
            ['user2', 1, 0],
            ['user2', 2, 0],
        ];
    }

    public static function findUnassigned_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 0],
            //nonexisting model
            ['user1', 3, 0],
            //nonexisting user
            ['user3', 1, 0],
            //User has mixed models
            ['user2', 1, 2],
            ['user2', 2, 0],
        ];
    }

    public static function findAll_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 6],
            //nonexisting model
            ['user1', 3, 0],
            //nonexisting user
            ['user3', 1, 0],
            //User has mixed models
            ['user2', 1, 3],
            ['user2', 2, 3],
        ];
    }

    public static function findDistinctNames_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 2],
            //nonexisting model
            ['user1', 3, 0],
            //nonexisting user
            ['user3', 1, 0],
            //User has mixed models
            ['user2', 1, 1],
            ['user2', 2, 1],
        ];
    }

    public static function findDistinctNamesSelected_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 'Alice', 1],
            ['user1', 1, 'Bob', 1],
            //nonexisting model
            ['user1', 3, 'Alice', 0],
            //nonexisting user
            ['user3', 1, 'Dummy', 0],
            //User has mixed models
            ['user2', 1, 'Alice', 0],
            ['user2', 2, 'Bob', 1],
        ];
    }

    public static function findPersonsLike_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 'Alice', null, null, 1],
            ['user1', 1, 'Alice', 0, 1, 1],
            ['user1', 1, 'Bob', null, null, 1],
            ['user1', 1, 'Alice', 1, 1, 0],
            ['user1', 1, 'Bob', 1, 1, 0],
            ['user1', 1, 'Al', 1, 1, 0],
            ['user1', 1, 'Al', 0, 1, 1],
            ['user1', 1, 'Bo', 1, 1, 0],
            //nonexisting model
            ['user1', 3, 'Alice', null, null, 0],
            ['user1', 3, 'Alice', 1, 1, 0],
            //nonexisting user
            ['user3', 1, 'Dummy', null, null,  0],
            ['user3', 1, 'Dummy', 1, 1,  0],
            //User has mixed models
            ['user2', 1, 'Alice', null, null,  0],
            ['user2', 1, 'Alice', 1, 1,  0],
            ['user2', 2, 'Bob', null, null,  1],
            ['user2', 2, 'Bob', 1, 1,  0],
            ['user2', 2, 'Bob', 0, 1,  1],
            ['user2', 2, 'Bo', null, null,  1],
            ['user2', 2, 'Bo', 0, 1,  1],
            ['user2', 2, 'Bo', 1, 1,  0],
        ];
    }

    public static function countPersons_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, 2],
            //nonexisting model
            ['user1', 3, 0],
            //nonexisting user
            ['user3', 1, 0],
            //User has mixed models
            ['user2', 1, 1],
            ['user2', 2, 1],
        ];
    }

    public static function countClusters_Provider(): array
    {
        return [
            //Existing user and model
            ['user1', 1, false, 6],
            ['user1', 1, true, 0],
            //nonexisting model
            ['user1', 3, false, 0],
            ['user1', 3, true, 0],
            //nonexisting user
            ['user3', 1, false, 0],
            ['user3', 1, true, 0],
            //User has mixed models
            ['user2', 1, false, 3],
            ['user2', 1, true, 0],
            ['user2', 2, false, 3],
            ['user2', 2, true, 2],
        ];
    }

    public static function invalidatePersons_Provider(): array
    {
        return [
            //Single File
            [1, "user1", 1],
            //SharedFile
            [10, "user1", 6],
            [10, "user2", 6],
            //NonexistingFile
            [100, "user1", 0],
        ];
    }

    public static function deleteUserPersons_Provider(): array
    {
        return [
            //User with single model
            ['user1'],
            //User with multiple model
            ['user2'],
            //Nonexisting User
            ['user3'],
        ];
    }

    public static function deleteUserModel_Provider(): array
    {
        return [
            //User with single model
            ['user1', 1],
            //User with not attached model
            ['user1', 2],
            //User with multiple model
            ['user2', 1],
            ['user2', 2],
            //Nonexisting user
            ['user3', 1],
            //Nonexisting model
            ['user2', 3],
            //Nonexisting user and model
            ['user3', 3],
        ];
    }

    public static function removeIfEmpty_Provider(): array
    {
        return [
            //Multiple face
            [1, false],
            //Single face
            [3, false],
            //No face
            [7, true],
            //Invalid Id
            [100, true],
        ];
    }

    public static function deleteOrphaned_Provider(): array
    {
        return [
            //User with single model
            ['user1', 1],
            //User with multiple model
            ['user2', 1],
            //Nonexisting User
            ['user3', 0],
        ];
    }

    public static function setVisibility_Provider(): array
    {
        return [
            [1, false],
            [1, true],
            [3, false],
            [3, true],
            [10, false],
            [10, true]
        ];
    }

    public static function detachFace_Provider(): array
    {
        return [
            //multi face cluster has default name
            [2, 100, null],
            [2, 100, 'Dummy'],
            //Singe face cluster no default name
            [4, 4, null],
            [4, 4, 'Dummy1'],
            //Single face cluster has default name
            [1, 1, null],
            [1, 1, 'Dummy2'],
        ];
    }

    public static function insertPersonIfNotExists_Provider(): array
    {
        return [
            //Existing name
            ['Alice', 1],
            //NonExisting name
            ['Dummy', 3],
        ];
    }

    public static function updateClusterPersonConnection_Provider(): array
    {
        //int $clusterId, ?string $personName, int $expectedId, int $expectedpersonCount
        return [
            //remove Existing connection
            [1, null, 0, 1],
            //rename Existing connection
            [1, 'Dummy', 3, 2],
            //NonExisting connection
            [3, 'Dummy', 3, 3],
            //No connection created
            [3, null, 0, 2],
        ];
    }

    public static function updateClusterPersonConnection_error_Provider(): array
    {
        //int $clusterId, ?string $personName, int $expectedId, int $expectedpersonCount
        return [
            //remove Existing connection
            [1, null, 0, 2],
            //rename Existing connection
            [1, 'Dummy', 3, 2],
        ];
    }

    public static function countClusterFaces_Provider(): array
    {
        return [
            [1, 2],
            [2, 2],
            [3, 1],
            [10, 2]
        ];
    }

    public static function updateFace_Provider(): array
    {
        //int $faceId, ?int $oldClusterId, ?int $clusterId, bool $isGroupable, bool $expectedError
        return [
            //invalid reuest
            [1, null, null, true, true],
            [1, null, null, false, true],
            //Non attached face
            [9, null, 4, true, false],
            [9, null, 4, false, false],
            //migrate face
            [1, 1, 4, true, false],
            [1, 1, 4, false, false],
            //detach face
            [1, 1, null, true, false],
            [1, 1, null, false, false],
        ];
    }

    public static function removeAllFacesFromCluster_Provider(): array
    {
        return [
            //multiple faces
            [1],
            //Single face
            [3],
            //no face
            [7],
        ];
    }

    public static function attachFaceToCluster_Provider(): array
    {
        return [
            //invalid connection
            [1, 1, true, "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1' for key 'PRIMARY'"],
            //Single face
            [3, 5, false, null],
            //to empty cluster
            [7, 100, false, null],
            //nonexisting cluster
            [100, 100, true, "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`nextcloud_db`.`oc_facerecog_cluster_faces`, CONSTRAINT `FK_CF21BF85C36A3328` FOREIGN KEY (`cluster_id`) REFERENCES `oc_facerecog_clusters` (`id`) ON DELETE CASCADE)"],
            //nonexisting face
            [1, 1000, true, "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`nextcloud_db`.`oc_facerecog_cluster_faces`, CONSTRAINT `FK_CF21BF85FDC86CD0` FOREIGN KEY (`face_id`) REFERENCES `oc_facerecog_faces` (`id`) ON DELETE CASCADE)"],
            //nonexisting cluster and face
            [100, 1000, true, "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`nextcloud_db`.`oc_facerecog_cluster_faces`, CONSTRAINT `FK_CF21BF85C36A3328` FOREIGN KEY (`cluster_id`) REFERENCES `oc_facerecog_clusters` (`id`) ON DELETE CASCADE)"],
        ];
    }

    public static function mergeClusterToDatabase_Provider(): array
    {
        //string $userId, array $currentClusters, array $newClusters, int $modifiedCount, int $addedCount, int $deletedCount
        return [
            //Create new clusters
            ["user1", array(), array(100 => [1]), 0, 1, 0],
            ["user1", array(), array(100 => [1, 3, 5, 7]), 0, 1, 0],
            ["user1", array(), array(100 => [1, 3], 101 => [5, 7]), 0, 2, 0],
            //Update existing cluster
            ["user1", array(3 => [3]), array(3 => [3]), 1, 0, 0],
            ["user1", array(1 => [1, 7]), array(1 => [1]), 1, 0, 0],
            ["user1", array(3 => [3]), array(3 => [1, 3]), 1, 0, 0],
            ["user1", array(3 => [3]), array(3 => [1, 3, 5, 7]), 1, 0, 0],
            ["user1", array(3 => [3]), array(3 => [1, 3], 101 => [5, 7]), 1, 1, 0],
            ["user1", array(1 => [1, 7], 3 => [3]), array(1 => [1], 3 => [1, 3]), 2, 0, 0],
            //remove new clusters
            ["user1", array(3 => [3]), array(), 0, 0, 1],
            ["user1", array(1 => [1, 7]), array(), 0, 0, 1],
            ["user1", array(1 => [1, 7], 3 => [3]), array(), 0, 0, 2],
            //recreated clusters
            ["user1", array(1 => [1, 7], 3 => [3]), array(100 => [1, 7], 101 => [3]), 0, 2, 2],
            //Complex clusters
            ["user1",
                array(1 => [1, 7], 3 => [3], 10 => [100, 101], 12 => [102, 103], 14 => [104, 105]),
                array(1 => [1, 7], 10 => [3, 100, 101], 12 => [102, 103, 104], 100 => [105]), 3, 1, 2],
        ];
    }
}

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

use OCA\FaceRecognition\Tests\Unit\UnitBaseTestCase;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Face;

#[CoversClass(FaceMapper::class)]
#[UsesClass(Face::class)]
class FaceMapperTest extends UnitBaseTestCase
{
	/** @var FaceMapper test instance*/
	private static $faceMapper;
	private static $clusterFaceCountQuery;
	private static $faceCountQuery;

    /**
     * {@inheritDoc}
     */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
  		self::$faceMapper = new FaceMapper(self::$dbConnection, self::$logger);
		self::$clusterFaceCountQuery = self::$dbConnection->getQueryBuilder();
		self::$clusterFaceCountQuery->select(self::$clusterFaceCountQuery->createFunction('COUNT(*) as count'))->from('facerecog_cluster_faces');

		self::$faceCountQuery = self::$dbConnection->getQueryBuilder();
		self::$faceCountQuery->select(self::$faceCountQuery->createFunction('COUNT(id) as count'))->from('facerecog_faces');
	}

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void{
		parent::setUp();
	}

	public function test_FindById_existingFace_connectedCluster(): void{
		//Act
		$face = self::$faceMapper->find(1, "user1"); //face id 1 belongs to user1

		//Assert
		$this->assertNotNull($face);
		$this->assertInstanceOf(Face::class, $face);
		$this->assertEquals(1, $face->getId());
		$this->assertEquals(1, $face->getImage());
		$this->assertEquals(1, $face->getPerson());
		$this->assertEquals(true, $face->getIsGroupable());
		$this->assertEquals('[0.23,-0.45,0.67,-0.12,0.89,-0.34,0.56,-0.78,0.9,-0.11,0.22,-0.33,0.44,-0.55,0.66,-0.77,0.88,-0.99,0.01,-0.02,0.03,-0.04,0.05,-0.06,0.07,-0.08,0.09,-0.1,0.11,-0.12,0.13,-0.14,0.15,-0.16,0.17,-0.18,0.19,-0.2,0.21,-0.22,0.23,-0.24,0.25,-0.26,0.27,-0.28,0.29,-0.3,0.31,-0.32,0.33,-0.34,0.35,-0.36,0.37,-0.38,0.39,-0.4,0.41,-0.42,0.43,-0.44,0.45,-0.46,0.47,-0.48,0.49,-0.5,0.51,-0.52,0.53,-0.54,0.55,-0.56,0.57,-0.58,0.59,-0.6,0.61,-0.62,0.63,-0.64,0.65,-0.66,0.67,-0.68,0.69,-0.7,0.71,-0.72,0.73,-0.74,0.75,-0.76,0.77,-0.78,0.79,-0.8,0.81,-0.82,0.83,-0.84,0.85,-0.86,0.87,-0.88,0.89,-0.9,0.91,-0.92,0.93,-0.94,0.95,-0.96,0.97,-0.98,0.99,-1,0.01,0.02,0.03,0.04,0.05,0.06,0.07,0.08,0.09,0.1]', $face->getDescriptor());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:06:00'), $face->getCreationTime());
		$this->assertEquals(0.98, $face->getConfidence());
		$this->assertEquals('"[\n    {\"x\": 12, \"y\": 34},\n    {\"x\": 45, \"y\": 67},\n    {\"x\": 23, \"y\": 56},\n    {\"x\": 78, \"y\": 12},\n    {\"x\": 34, \"y\": 89},\n    {\"x\": 56, \"y\": 23}\n  ]"', $face->getLandmarks());
		$this->assertEquals(10, $face->getX());
		$this->assertEquals(20, $face->getY());
		$this->assertEquals(30, $face->getWidth());
		$this->assertEquals(40, $face->getHeight());
	}

	public function test_FindById_existingFace_NOTconnectedCluster(): void{
		//Act
		$face = self::$faceMapper->find(9, "user1"); //face id 1 belongs to user1

		//Assert
		$this->assertNotNull($face);
		$this->assertInstanceOf(Face::class, $face);
		$this->assertEquals(9, $face->getId());
		$this->assertEquals(8, $face->getImage());
		$this->assertNull($face->getPerson());
		$this->assertNotNull($face->getDescriptor());
		$this->assertNotNull($face->getLandmarks());
		$this->assertEquals(true, $face->getIsGroupable());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:57:00'), $face->getCreationTime());
		$this->assertEquals(0.99, $face->getConfidence());
		$this->assertEquals(29, $face->getX());
		$this->assertEquals(39, $face->getY());
		$this->assertEquals(49, $face->getWidth());
		$this->assertEquals(59, $face->getHeight());
	}

	public function test_FindById_existingFace_connectedToMultipleCluster(): void{
		//Act
		$face = self::$faceMapper->find(100, "user1"); //face id 1 belongs to user1

		//Assert
		$this->assertNotNull($face);
		$this->assertInstanceOf(Face::class, $face);
		$this->assertEquals(100, $face->getId());
		$this->assertEquals(10, $face->getImage());
		$this->assertEquals(10, $face->getPerson());
		$this->assertNotNull($face->getDescriptor());
		$this->assertNotNull($face->getLandmarks());
		$this->assertEquals(true, $face->getIsGroupable());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-28 12:01:00'), $face->getCreationTime());
		$this->assertEquals(0.95, $face->getConfidence());
		$this->assertEquals(10, $face->getX());
		$this->assertEquals(20, $face->getY());
		$this->assertEquals(30, $face->getWidth());
		$this->assertEquals(40, $face->getHeight());
	}

	public function test_FindById_nonExisting(): void{
		//Act
		$face = self::$faceMapper->find(1000, "user1");
		//Assert
		$this->assertNull($face);
	}

	public function test_FindDescriptorsBathed_multipleEntry(): void{
		//Act
		$descriptors = self::$faceMapper->findDescriptorsBathed([1, 2]);

		//Assert
		$this->assertNotNull($descriptors);
		$this->assertIsArray($descriptors);
		$this->assertCount(2, $descriptors);

		$firstDescriptor = $descriptors[0];
		$secondDescriptor = $descriptors[1];

		$this->assertIsArray($firstDescriptor['descriptor']);
		$this->assertCount(128, $firstDescriptor['descriptor']);
		$this->assertEquals(1, $firstDescriptor['id']);
		$this->assertIsArray($secondDescriptor['descriptor']);
		$this->assertCount(128, $secondDescriptor['descriptor']);
		$this->assertEquals(2, $secondDescriptor['id']);
	}

	public function test_FindDescriptorsBathed_singleEntry(): void{
		//Act
		$descriptors = self::$faceMapper->findDescriptorsBathed([1]);

		//Assert
		$this->assertNotNull($descriptors);
		$this->assertIsArray($descriptors);
		$this->assertCount(1, $descriptors);

		$firstDescriptor = $descriptors[0];

		$this->assertIsArray($firstDescriptor['descriptor']);
		$this->assertCount(128, $firstDescriptor['descriptor']);
		$this->assertEquals(1, $firstDescriptor['id']);
	}

	public function test_FindDescriptorsBathed_emptyarray(): void{
		//Act
		$descriptors = self::$faceMapper->findDescriptorsBathed([]);

		//Assert
		$this->assertNotNull($descriptors);
		$this->assertIsArray($descriptors);
		$this->assertEmpty($descriptors);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'findFromFile_Provider')]
	public function test_FindFromFile($fileId, $expectedCount): void{
		//Act
		$faces = self::$faceMapper->findFromFile("user1", 1, $fileId);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	public function test_CountFaces_ForUser_OnlyWithoutPerson(): void{
		//Act
		$facesCount = self::$faceMapper->countFaces("user2", 2, true);

		//Assert
		$this->assertNotNull($facesCount);
		$this->assertEquals(1, $facesCount);
	}

	public function test_CountFaces_ForUser(): void{
		//Act
		$facesCount = self::$faceMapper->countFaces("user1", 1, false);

		//Assert
		$this->assertNotNull($facesCount);
		$this->assertEquals(16, $facesCount);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'getOldestCreatedFaceWithoutPerson_ForUser_ByModel_Provider')]
	public function test_GetOldestCreatedFaceWithoutPerson_ForUser_ByModel(string $user, int $model, bool $isFaceNull): void{
		//Act
		$face = self::$faceMapper->getOldestCreatedFaceWithoutPerson($user, $model);

		//Assert
		if ($isFaceNull) {
			$this->assertNull($face);
		} else {
			$this->assertNotNull($face);
			$this->assertInstanceOf(Face::class, $face);
			$this->assertNotNull($face->getId());
			$this->assertInstanceOf(DateTime::class, $face->getCreationTime());
			$this->assertNotNull($face->getCreationTime());
			$this->assertNotNull($face->getImage());
			$this->assertNull($face->getPerson());
			$this->assertNotEquals("null", $face->getDescriptor());
			$this->assertNotEquals("null", $face->getLandmarks());
			$this->assertNotNull($face->getConfidence());
			$this->assertNotNull($face->getX());
			$this->assertNotNull($face->getY());
			$this->assertNotNull($face->getWidth());
			$this->assertNotNull($face->getHeight());
		}
	}

	#[DataProviderExternal(FaceDataProvider::class, 'getFaces_ForUser_ByModel_Provider')]
	public function test_GetFaces_ForUser_ByModel(string $user, int $model, int $expectedCount): void{
		//Act
		$faces = self::$faceMapper->getFaces($user, $model);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'getGroupableFaces_ForUser_ByModel_MinSize_MinConfidence_Provider')]
	public function test_GetGroupableFaces_ForUser_ByModel_MinSize_MinConfidence(int $minSize, float $minConfidence, int $expectedCount): void{
		//Act
		$faces = self::$faceMapper->getGroupableFaces("user1", 1, $minSize, $minConfidence);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'getNonGroupableFaces_ForUser_ByModel_MinSize_MinConfidence_Provider')]
	public function test_GetNonGroupableFaces_ForUser_ByModel_MinSize_MinConfidence(int $minSize, float $minConfidence, int $expectedCount): void{
		//Act
		$faces = self::$faceMapper->getNonGroupableFaces("user1", 1, $minSize, $minConfidence);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'findFromCluster_ForUser_ByClusterId_ByModel_Limit_Offset_Provider')]
	public function test_FindFromCluster_ForUser_ByClusterId_ByModel_Limit_Offset(int $clusterId, ?int $limit, ?int $offset, int $expectedCount): void{
		//Act
		$faces = self::$faceMapper->findFromCluster("user1", $clusterId, 1, $limit, $offset);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'findFromPerson_ForUser_ByPersonName_ByModel_Limit_Offset_Provider')]
	public function test_FindFromPerson_ForUser_ByClusterId_ByModel_Limit_Offset(string $personName, ?int $limit, ?int $offset, int $expectedCount): void{
		//Act
		$faces = self::$faceMapper->findFromPerson("user1", $personName, 1, $limit, $offset);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'findByImage_Provider')]
	public function test_findByImage(int $imageId, int $expectedCount): void{
		//Act
		$faces = self::$faceMapper->findByImage($imageId);

		//Assert
		$this->assertNotNull($faces);
		$this->assertIsArray($faces);
		$this->assertContainsOnlyInstancesOf(Face::class, $faces);
		$this->assertCount($expectedCount, $faces);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'removeFromImage_Provider')]
	public function test_RemoveFromImage(int $imageId, int $expectedCount): void{
		//Act
		self::$faceMapper->removeFromImage($imageId);

		//Assert
		$qb = self::$dbConnection->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(id) as count'))->from('facerecog_faces')->where($qb->expr()->eq('image_id', $qb->createNamedParameter($imageId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count']);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'removeFromImage_Provider')]
	public function test_RemoveFromImage_WithDbConnection(int $imageId, int $expectedCount): void{
		//Act
		self::$faceMapper->removeFromImage($imageId, self::$dbConnection);

		//Assert
		$qb = self::$dbConnection->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(id) as count'))->from('facerecog_faces')->where($qb->expr()->eq('image_id', $qb->createNamedParameter($imageId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count']);
	}

	public function test_DeleteUserModel(): void{
		//Act
		self::$faceMapper->deleteUserModel('user2', 2);

		//Assert
		$this->assertFaceCount(10);
		$this->assertFaceClusterConnectionCount(16);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'unsetPersonsRelationForUser_Provider')]
	public function test_UnsetPersonsRelationForUser(string $user, int $model, int $expectedCount): void{
		//Act
		self::$faceMapper->unsetPersonsRelationForUser($user, $model);

		//Assert
		$this->assertFaceCount(15);
		$this->assertFaceClusterConnectionCount($expectedCount);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'insertFace_Provider')]
	public function test_InsertFace(Face $faceToInsert, int $expectedFaceCount, int $expectedConnectionCount): void{

		//Act
		self::$faceMapper->insertFace($faceToInsert);

		//Assert
		$this->assertFaceCount($expectedFaceCount);
		$this->assertFaceClusterConnectionCount($expectedConnectionCount);
	}

	#[DataProviderExternal(FaceDataProvider::class, 'insertFace_Provider')]
	public function test_InsertFace_withDbContext(Face $faceToInsert, int $expectedFaceCount, int $expectedConnectionCount): void{
		//Act
		self::$faceMapper->insertFace($faceToInsert, self::$dbConnection);

		//Assert
		$this->assertFaceCount($expectedFaceCount);
		$this->assertFaceClusterConnectionCount($expectedConnectionCount);
	}

	public function test_large_FindDescriptorsBathed_moreThan1000Entry(): void{
		if (!$this->runLargeTests)
		{
			$this->assertTrue(true);
			return;
		}
		$sql = file_get_contents("tests/DatabaseInserts/31_1005FacesInsert.sql");
		self::$dbConnection->executeStatement($sql);
		$faceIds = [];
		for ($i = 1001; $i < 2006; $i++)
		{
			$faceIds[] = $i;
		}
		$faceIds[] = 1;
		$faceIds[] = 2;
		$faceIds[] = 3;
		$faceIds[] = 4;
		$faceIds[] = 5;

		//Act
		$descriptors = self::$faceMapper->findDescriptorsBathed($faceIds);

		//Assert
		$this->assertCount(1010, $faceIds);
		$this->assertNotNull($descriptors);
		$this->assertIsArray($descriptors);
		$this->assertCount(1010, $descriptors);

		foreach ($descriptors as $currentDescriptor)
		{
			$this->assertIsArray($currentDescriptor['descriptor']);
			$this->assertCount(128, $currentDescriptor['descriptor'], 'Error with faceId: '.$currentDescriptor['id']);
			$this->assertContains($currentDescriptor['id'], $faceIds);
		}
		$descriptorIds = array_column($descriptors, 'id');
		foreach ($faceIds as $id)
		{

			$this->assertContains($id, $descriptorIds,'Error with faceId: '.$currentDescriptor['id']);
		}
	}

	public function test_large_UnsetPersonsRelationForUser_with1000Faces(): void{
		if (!$this->runLargeTests)
		{
			$this->assertTrue(true);
			return;
		}
		$sql = file_get_contents("tests/DatabaseInserts/31_1005FacesInsert.sql");
		self::$dbConnection->executeStatement($sql);
		$sql = file_get_contents("tests/DatabaseInserts/51_1005FaceClusterConnection.sql");
		self::$dbConnection->executeStatement($sql);

		//Act
		self::$faceMapper->unsetPersonsRelationForUser('user1', 1);

		//Assert
		$this->assertFaceCount(1020);
		$this->assertFaceClusterConnectionCount(10);
	}

	public function tearDown(): void{
		parent::tearDown();
	}

	public static function tearDownAfterClass(): void {
		self::$faceMapper = null;
		self::$clusterFaceCountQuery = null;
		self::$faceCountQuery = null;
		parent::tearDownAfterClass();
	}

	private function assertFaceCount($expectedCount){
		$row  = self::$faceCountQuery->executeQuery()->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count']);
	}

	private function assertFaceClusterConnectionCount($expectedCount){
		$row  = self::$clusterFaceCountQuery->executeQuery()->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count']);
	}
}

class FaceDataProvider
{
	public static function findFromFile_Provider(): array{
		return [
			[101, 1],
			[102, 0], //file not for this user
			[103, 1], //file for this user, but no person assigned faces
			[999, 0], //non existing file
			[201, 6]
		];
	}

	public static function getFaces_ForUser_ByModel_Provider(): array{
		return [
			["user1", 1, 10],
			["user1", 2, 0],
			["user2", 1, 6],
			["user2", 2, 5],
			["user2", 6, 0], //non existing model
			["user3", 1, 0], //non existing user
			["user3", 6, 0]  //non existing user and model
		];
	}
	public static function getOldestCreatedFaceWithoutPerson_ForUser_ByModel_Provider(): array{
		return [
			["user1", 1, true],
			["user1", 2, true],
			["user2", 1, true],
			["user2", 2, false]
		];
	}

	public static function getGroupableFaces_ForUser_ByModel_MinSize_MinConfidence_Provider(): array{
		return [
			[20, 0.97, 1],
			[500, 0.97, 0],
			[5000, 0, 0],
			[0, 0, 15],
			[0, 1, 0],
			[20, 0.85, 14],
		];
	}

	public static function getNonGroupableFaces_ForUser_ByModel_MinSize_MinConfidence_Provider(): array{
		return [
			[20, 0.97, 15],
			[500, 0.97, 16],
			[5000, 0, 16],
			[0, 0, 1],
			[0, 1, 16],
			[20, 0.85, 2],
		];
	}

	public static function findFromCluster_ForUser_ByClusterId_ByModel_Limit_Offset_Provider(): array{
		return [
			[1, null, null, 2],
			[10, null, null, 2],
			[1, 1, 0, 1],
			[10, 1, 0, 1],
			[10, 1, 1, 1],
		];
	}

	public static function findFromPerson_ForUser_ByPersonName_ByModel_Limit_Offset_Provider(): array{
		return [
			['Alice', null, null, 2],
			['Alice', 1, 1, 1],
			['Alice', 1, 0, 1],
			['Dummy', null, null, 0]
		];
	}

	public static function findByImage_Provider(): array{
		return [
			[1, 1],
			[10, 6]
		];
	}

	public static function removeFromImage_Provider(): array{
		return [
			[1, 0],
			[10, 0],
			[999, 0] //non existing image
		];
	}

	public static function unsetPersonsRelationForUser_Provider(): array{
		return [
			['user1', 1, 10],
			['user2', 2, 16],
			['user2', 1, 14],
			['user1', 2, 20], //no faces for user1 and model 2, so no change
			['user3', 4, 20], //no faces for user3 and model 4, so no change
		];
	}

	public static function insertFace_Provider(): array{
		$face1 = new Face();
		$face1->setImage(1);
		$face1->setX(10);
		$face1->setY(20);
		$face1->setWidth(30);
		$face1->setHeight(40);
		$face1->setConfidence(0.95);
		$face1->setDescriptor('[0.01,0.02,0.03,0.04,0.05,0.06,0.07,0.08,0.09,0.1,0.11,0.12,0.13,0.14,0.15,0.16,0.17]');
		$face1->setLandmarks('"[{\"x\": 1, \"y\": 2}, {\"x\": 3, \"y\": 4}, {\"x\": 5, \"y\": 6}, {\"x\": 7, \"y\": 8}, {\"x\": 9, \"y\": 10}, {\"x\": 11, \"y\": 12}]"');
		$face1->setCreationTime(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-30 12:00:00'));
		$face1->setIsGroupable(true);
		$face1->setPerson(1); //Alice
		$face2 = new Face();
		$face2->setImage(1);
		$face2->setX(10);
		$face2->setY(20);
		$face2->setWidth(30);
		$face2->setHeight(40);
		$face2->setConfidence(0.95);
		$face2->setDescriptor('[0.01,0.02,0.03,0.04,0.05,0.06,0.07,0.08,0.09,0.1,0.11,0.12,0.13,0.14,0.15,0.16,0.17]');
		$face2->setLandmarks('"[{\"x\": 1, \"y\": 2}, {\"x\": 3, \"y\": 4}, {\"x\": 5, \"y\": 6}, {\"x\": 7, \"y\": 8}, {\"x\": 9, \"y\": 10}, {\"x\": 11, \"y\": 12}]"');
		$face2->setCreationTime(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-30 12:00:00'));
		$face2->setIsGroupable(true);
		$face2->setPerson(null); //Alice	
		return [
			[$face1, 16, 21],
			[$face2, 16, 20],
		];
	}
}

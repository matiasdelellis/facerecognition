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
use Exception;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use OCA\FaceRecognition\Tests\Unit\UnitBaseTestCase;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\Face;
use OC;

#[CoversClass(ImageMapper::class)]
#[UsesClass(FaceMapper::class)]
#[UsesClass(Image::class)]
#[UsesClass(Face::class)]
class ImageMapperTest extends UnitBaseTestCase
{
	/** @var ImageMapper test instance*/
	private static $imageMapper;
	/** @var FaceMapper test instance*/
	private static $faceMapper;
	private static $imageCountQuery;
	private static $userImageCountQuery;
	private static $faceCountQuery;

    /**
     * {@inheritDoc}
     */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$faceMapper = new FaceMapper(self::$dbConnection, self::$logger);
		self::$imageMapper = new ImageMapper(self::$dbConnection, self::$faceMapper, self::$logger);

		self::$imageCountQuery = self::$dbConnection->getQueryBuilder();
		self::$imageCountQuery->select(self::$imageCountQuery->createFunction('COUNT(id) as count'))->from('facerecog_images');

		self::$userImageCountQuery = self::$dbConnection->getQueryBuilder();
		self::$userImageCountQuery->select(self::$userImageCountQuery->createFunction('COUNT(*) as count'))->from('facerecog_user_images');

		self::$faceCountQuery = self::$dbConnection->getQueryBuilder();
		self::$faceCountQuery->select(self::$faceCountQuery->createFunction('COUNT(*) as count'))->from('facerecog_faces');
	}
	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void{
		parent::setUp();
	}

	public function test_Find(): void{
		//Act
		$image = self::$imageMapper->find('user1', 1);

		//Assert
		$this->assertNotNull($image);
		$this->assertInstanceOf(Image::class, $image);
		$this->assertEquals(1, $image->getId());
		$this->assertEquals(1, $image->getModel());
		$this->assertEquals(120, $image->getProcessingDuration());
		$this->assertEquals('user1', $image->getUser());
		$this->assertEquals(101, $image->getFile());
		$this->assertEquals(true, $image->getIsProcessed());
		$this->assertEquals(null, $image->getError());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-26 10:05:00'), $image->getLastProcessedTime());
	}

	public function test_Find_ConnectedToMultipleUser(): void{
		//Act
		$image = self::$imageMapper->find('user2', 10);

		//Assert
		$this->assertNotNull($image);
		$this->assertInstanceOf(Image::class, $image);
		$this->assertEquals(10, $image->getId());
		$this->assertEquals(1, $image->getModel());
		$this->assertEquals(100, $image->getProcessingDuration());
		$this->assertEquals('user2', $image->getUser());
		$this->assertEquals(201, $image->getFile());
		$this->assertEquals(true, $image->getIsProcessed());
		$this->assertEquals(null, $image->getError());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-28 12:00:00'), $image->getLastProcessedTime());
		unset($image);

		//Act
		$image = self::$imageMapper->find('user1', 10);

		//Assert
		$this->assertNotNull($image);
		$this->assertInstanceOf(Image::class, $image);
		$this->assertEquals(10, $image->getId());
		$this->assertEquals(1, $image->getModel());
		$this->assertEquals(100, $image->getProcessingDuration());
		$this->assertEquals('user1', $image->getUser());
		$this->assertEquals(201, $image->getFile());
		$this->assertEquals(true, $image->getIsProcessed());
		$this->assertEquals(null, $image->getError());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d H:i:s', '2025-08-28 12:00:00'), $image->getLastProcessedTime());
	}

	#[DataProviderExternal(ImageDataProvider::class, 'find_Provider')]
	public function test_Find_InvalidQuery(string $userId, int $modelId): void{
		//Act
		$image = self::$imageMapper->find($userId, $modelId);

		//Assert
		$this->assertNull($image);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'findAll_Provider')]
	public function test_FindAll(string $user, int $model, int $expectedCount): void{
		//Act
		$images = self::$imageMapper->findAll($user, $model);

		//Assert
		$this->assertNotNull($images);
		$this->assertIsArray($images);
		$this->assertContainsOnlyInstancesOf(Image::class, $images);
		$this->assertCount($expectedCount, $images);
		foreach ($images as $image) {
			$this->assertEquals($user, $image->getUser());
			$this->assertEquals($model, $image->getModel());
		}
	}

	#[DataProviderExternal(ImageDataProvider::class, 'findFromFile_Provider')]
	public function test_findFromFile(string $user, int $model, int $nc_file_id, ?int $expectedId): void{
		//Act
		$image = self::$imageMapper->findFromFile($user, $model, $nc_file_id);

		//Assert
		if ($expectedId === null) {
			$this->assertNull($image);
		} else {
			$this->assertNotNull($image);
			$this->assertInstanceOf(Image::class, $image);
			$this->assertEquals($expectedId, $image->getId());
			$this->assertEquals($user, $image->getUser());
			$this->assertEquals($model, $image->getModel());
		}
	}

	#[DataProviderExternal(ImageDataProvider::class, 'otherUserStilHasConnection_Provider')]
	public function test_otherUserStilHasConnection(int $imageId, bool $expectedResult): void{
		//Act
		$hasConnection = self::$imageMapper->otherUserStilHasConnection($imageId);

		//Assert
		$this->assertEquals($expectedResult, $hasConnection);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'removeUserImageConnection_Provider')]
	public function test_removeUserImageConnection(string $user, int $imageId, int $expectedConnections): void{
		$image = new Image();
		$image->id = $imageId;
		$image->user = $user;

		//Act
		self::$imageMapper->removeUserImageConnection($image);

		//Assert
		$this->assertRowCountImages(10);
		$this->assertRowCountUserImages($expectedConnections);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'findFromFile_Provider')]
	public function test_imageExists(string $user, int $model, int $nc_file_id, ?int $expectedId): void{
		$image = new Image();
		$image->user = $user;
		$image->file = $nc_file_id;
		$image->setModel($model);

		//Act
		$resultId = self::$imageMapper->imageExists($image);

		//Assert
		if ($expectedId === null) {
			$this->assertNull($resultId);
		} else {
			$this->assertNotNull($resultId);
			$this->assertEquals($expectedId, $resultId);
		}
	}

	#[DataProviderExternal(ImageDataProvider::class, 'countImages_Provider')]
	public function test_countImages(int $model, int $expectedCount): void{
		//Act
		$resultCount = self::$imageMapper->countImages($model);

		//Assert
		$this->assertEquals($expectedCount, $resultCount);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'countProcessedImages_Provider')]
	public function test_countProcessedImages(int $model, int $expectedCount): void{
		//Act
		$resultCount = self::$imageMapper->countProcessedImages($model);

		//Assert
		$this->assertEquals($expectedCount, $resultCount);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'avgProcessingDuration_Provider')]
	public function test_avgProcessingDuration(int $model, int $expectedCount): void{
		//Act
		$resultCount = self::$imageMapper->avgProcessingDuration($model);

		//Assert
		$this->assertEquals($expectedCount, $resultCount);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'countUserImages_Provider')]
	public function test_countUserImages(string $user, int $model, bool $processed, int $expectedCount): void{
		//Act
		$resultCount = self::$imageMapper->countUserImages($user, $model, $processed);

		//Assert
		$this->assertNotNull($resultCount);
		$this->assertEquals($expectedCount, $resultCount);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'findImagesWithoutFaces_Provider')]
	public function test_findImagesWithoutFaces(?string $user, int $model, int $expectedCount): void{
		//Act
		$images = self::$imageMapper->findImagesWithoutFaces($user, $model);

		//Assert
		$this->assertNotNull($images);
		$this->assertIsArray($images);
		$this->assertContainsOnlyInstancesOf(Image::class, $images);
		$this->assertCount($expectedCount, $images);
		foreach ($images as $image) {
			if ($user !== null) {
				$this->assertEquals($user, $image->getUser());
			}
			else {
				$this->assertNotNull($image->getUser());
			}
			$this->assertEquals($model, $image->getModel());
		}
	}

	#[DataProviderExternal(ImageDataProvider::class, 'findImages_Provider')]
	public function test_findImages(string $user, int $model, int $expectedCount): void{
		//Act
		$images = self::$imageMapper->findImages($user, $model);

		//Assert
		$this->assertNotNull($images);
		$this->assertIsArray($images);
		$this->assertContainsOnlyInstancesOf(Image::class, $images);
		$this->assertCount($expectedCount, $images);
		foreach ($images as $image) {
			$this->assertEquals($user, $image->getUser());
			$this->assertEquals($model, $image->getModel());
		}
	}

	#[DataProviderExternal(ImageDataProvider::class, 'findFromPerson_Provider')]
	public function test_findFromPerson(string $user, int $model, string $name, ?int $offset, ?int $limit, int $expectedCount): void{
		//Act
		$images = self::$imageMapper->findFromPerson($user, $model, $name, $offset, $limit);

		//Assert
		$this->assertNotNull($images);
		$this->assertIsArray($images);
		$this->assertContainsOnlyInstancesOf(Image::class, $images);
		$this->assertCount($expectedCount, $images);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'countFromPerson_Provider')]
	public function test_countFromPerson(string $user, int $model, string $name, int $expectedCount): void{
		//Act
		$resultCount = self::$imageMapper->countFromPerson($user, $model, $name);

		//Assert
		$this->assertNotNull($resultCount);
		$this->assertEquals($expectedCount, $resultCount);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'imageProcessed_Provider')]
	public function test_imageProcessed(int $imageId, array $faces, int $duration, ?Exception $e, int $expectedFaceCount, ?Exception $expected = null): void{
		foreach ($faces as $face) {
			$face->setImage($imageId);
		}
		//Initial face count
		$this->assertRowCountFaces(15);
		if ($expected !== null) {
			$this->expectException(get_class($expected));
			$this->expectExceptionMessage($expected->getMessage());
		}

		//Act
		self::$imageMapper->imageProcessed($imageId, $faces, $duration, $e);

		//Assert
		$this->assertRowCountFaces($expectedFaceCount);
	}

	public function test_resetImage(): void{
		$image = self::$imageMapper->find('user1', 1);
		//Act
		self::$imageMapper->resetImage($image);

		//Assert
		$image = self::$imageMapper->find('user1', 1);
		$this->assertNotNull($image);
		$this->assertEquals(false, $image->getIsProcessed());
		$this->assertNull($image->getError());
		$this->assertNull($image->getLastProcessedTime());
	}

	public function test_resetErrors(): void{
		//Act
		self::$imageMapper->resetErrors('user2');

		//Assert
		$images = self::$imageMapper->findAll('user2', 2);
		$this->assertNotNull($images);
		foreach ($images as $image) {
			if ($image->getId() === 2 || $image->getId() === 4 || $image->getId() === 7) {
				$this->assertEquals(false, $image->getIsProcessed(), "Image id: " . $image->getId() . " is_processed set true, but it should be false");
				$this->assertNull($image->getError(), "Image id: " . $image->getId() . " error not null, but it should be null");
				$this->assertNull($image->getLastProcessedTime(), "Image id: " . $image->getId() . " last_processed_time not null, but it should be null");
				continue;
			}
		}
	}

	public function test_deleteUserImages(): void{
		//Act
		self::$imageMapper->deleteUserImages("user1");

		//Assert
		$this->assertRowCountUserImages(5);
		$this->assertRowCountImages(5);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'deleteUserModel_Provider')]
	public function test_deleteUserModel(string $userId, int $modelId, int $expectedConnections, int $expectedImages): void{
		//Act
		self::$imageMapper->deleteUserModel($userId, $modelId);

		//Assert
		$this->assertRowCountImages($expectedImages);
		$this->assertRowCountUserImages($expectedConnections);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'insert_Provider')]
	public function test_insert(
		?string $user,
		int $model,
		int $nc_file_id,
		int $expectedFileCount,
		int $expectedConnections,
		bool $exceptionExpected,
		?string $expectedErrorMessage
	): void {
		$image = new Image();
		$image->user = $user;
		$image->setModel($model);
		$image->file = $nc_file_id;

		//Act
		if ($exceptionExpected) {
			$this->expectException(\OC\DB\Exceptions\DbalException::class);
			$this->expectExceptionMessage($expectedErrorMessage);
		}
		self::$imageMapper->insert($image);

		//Assert
		$this->assertRowCountImages($expectedFileCount);
		$this->assertRowCountUserImages($expectedConnections);
	}

	#[DataProviderExternal(ImageDataProvider::class, 'update_Provider')]
	public function test_update(int $imageId, ?string $user, int $model, int $nc_file_id): void{
		$image = new Image();
		$image->id = 1;
		$image->user = "user1";
		$image->file = 101;

		$image->setId($imageId);
		$image->setUser($user);
		$image->setFile($nc_file_id);
		$image->setModel($model);

		//Act
		$image = self::$imageMapper->update($image);

		//Assert
		$this->assertInitialDBstate();
		$this->assertNotNull($image);
		$this->assertInstanceOf(Image::class, $image);
		$this->assertEquals($imageId, $image->getId());
		$this->assertEquals($model, $image->getModel());
		$this->assertEquals($user, $image->getUser());
		$this->assertEquals($nc_file_id, $image->getFile());
	}

	public function test_update_nochange(): void{
		$image = new Image();
		$image->id = 1;
		$image->user = "user1";
		$image->file = "101";

		//Act
		$image = self::$imageMapper->update($image);

		//Assert
		$this->assertInitialDBstate();
		$this->assertNotNull($image);
		$this->assertInstanceOf(Image::class, $image);
		$this->assertEquals(1, $image->getId());
		$this->assertEquals("user1", $image->getUser());
		$this->assertEquals(101, $image->getFile());
	}

	public function test_update_invalidId(): void{
		$image = new Image();
		$image->setUser("user1");

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Entity which should be updated has no id');

		//Act
		$image = self::$imageMapper->update($image);
	}
	#[DataProviderExternal(ImageDataProvider::class, 'delete_Provider')]
	public function test_delete(int $imageId, ?string $user, int $model, int $nc_file_id, int $imageCount, int $connectionCount): void{
		$image = new Image();
		$image->id = $imageId;
		$image->user = $user;
		$image->file = $nc_file_id;
		$image->setModel($model);

		//Act
		self::$imageMapper->delete($image);

		//Assert
		$this->assertRowCountImages($imageCount);
		$this->assertRowCountUserImages($connectionCount);
	}

	public function test_large_deleteUserImages_moreThan1000Entries(): void{
		if (!$this->runLargeTests)
		{
			$this->assertTrue(true);
			return;
		}
		$sql = file_get_contents("tests/DatabaseInserts/11_1005ImageWithErrorModel2.sql");
		self::$dbConnection->executeStatement($sql);
		$sql = file_get_contents("tests/DatabaseInserts/21_1005userImageConnectionForUser1.sql");
		self::$dbConnection->executeStatement($sql);

		//Act
		self::$imageMapper->deleteUserImages("user1");

		//Assert
		$this->assertRowCountUserImages(5);
		$this->assertRowCountImages(5);
	}

	public function test_large_deleteUserModel_moreThan1000Entries(): void{
		if (!$this->runLargeTests)
		{
			$this->assertTrue(true);
			return;
		}
		$sql = file_get_contents("tests/DatabaseInserts/11_1005ImageWithErrorModel2.sql");
		self::$dbConnection->executeStatement($sql);
		$sql = file_get_contents("tests/DatabaseInserts/21_1005userImageConnectionForUser1.sql");
		self::$dbConnection->executeStatement($sql);

		//Act
		self::$imageMapper->deleteUserModel("user1", 2);

		//Assert
		$this->assertRowCountUserImages(10);
		$this->assertRowCountImages(9);
	}

	public function test_large_resetErrors_moreThan1000Entries(): void{
		if (!$this->runLargeTests)
		{
			$this->assertTrue(true);
			return;
		}
		$sql = file_get_contents("tests/DatabaseInserts/11_1005ImageWithErrorModel2.sql");
		self::$dbConnection->executeStatement($sql);
		$sql = file_get_contents("tests/DatabaseInserts/21_1005userImageConnectionForUser1.sql");
		self::$dbConnection->executeStatement($sql);

		//Act
		self::$imageMapper->resetErrors('user1');

		//Assert
		$images = self::$imageMapper->findAll('user1', 2);
		$this->assertNotNull($images);
		foreach ($images as $image) {
			if ($image->getId() > 1000)
			{
				$this->assertEquals(false, $image->getIsProcessed(), "Image id: " . $image->getId() . " is_processed set true, but it should be false");
				$this->assertNull($image->getError(), "Image id: " . $image->getId() . " error not null, but it should be null");
				$this->assertNull($image->getLastProcessedTime(), "Image id: " . $image->getId() . " last_processed_time not null, but it should be null");
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void{
		parent::tearDown();
	}

	public static function tearDownAfterClass(): void {
		self::$imageMapper = null;
		self::$faceMapper = null;
		self::$imageCountQuery = null;
		self::$userImageCountQuery = null;

		parent::tearDownAfterClass();
	}

	private function assertInitialDBstate(): void{
		$this->assertRowCountImages(10);
		$this->assertRowCountUserImages(11);
	}

	private function assertRowCountImages(int $expectedCount): void{
		$row = self::$imageCountQuery->executeQuery()->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count'], "Expected image count: " . $expectedCount . " actual: " . (int)$row['count']);
	}

	private function assertRowCountUserImages($expectedCount): void{
		$row = self::$userImageCountQuery->executeQuery()->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count'], "Expected user_image count: " . $expectedCount . " actual: " . (int)$row['count']);
	}

	private function assertRowCountFaces($expectedCount): void{
		$row = self::$faceCountQuery->executeQuery()->fetch();
		$this->assertNotFalse($row);
		$this->assertEquals($expectedCount, (int)$row['count'], "Expected face count: " . $expectedCount . " actual: " . (int)$row['count']);
	}
}

class ImageDataProvider
{
	public static function find_Provider(): array{
		return [
			["user2", 1], //existing user and model, but not connected
			["user3", 1], //not existing user
			["user1", 10000], //not existing model
			["user3", 10000], //not existing user and model
		];
	}

	public static function findAll_Provider(): array{
		return [
			["user1", 1, 5],
			["user2", 1, 1],
			["user2", 2, 4],
			["user3", 1, 0], //not existing user
			["user1", 4, 0], //not existing model
			["user3", 4, 0], //not existing user and model
		];
	}

	public static function findFromFile_Provider(): array{
		return [
			["user1", 1, 101, 1], //
			["user1", 2, 101, null],
			["user1", 1, 201, 10], //
			["user1", 2, 201, null],
			["user2", 1, 102, null],
			["user2", 2, 102, 2], //
			["user3", 1, 101, null], //not existing user
			["user1", 4, 101, null], //not existing model
			["user1", 1, 301, null], //not existing nc_file_Id
			["user3", 4, 101, null], //not existing user and model
			["user3", 1, 301, null], //not existing user and file
			["user1", 4, 301, null], //not existing model and file
			["user3", 4, 301, null], //not existing user and model and file
		];
	}

	public static function removeUserImageConnection_Provider(): array{
		return [
			["user1", 1, 10], //Single image
			["user1", 10, 10], //Shared image
			["user1", 100, 11], //Not existing image
			["user3", 10, 11], //Not existing user
		];
	}

	public static function insert_Provider(): array{
		$nullException = "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'user' cannot be null";
		$duplicateException = "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-user1' for key 'PRIMARY'";
		return [
			//New FILE
			["user1", 1, 150, 11, 12, false, null], //existing user
			["user3", 1, 150, 11, 12, false, null], //not existing user
			[null, 1, 150, 11, 11, true, $nullException], // not validuser
			//Existing file
			["user1", 1, 101, 10, 11, true, $duplicateException], // Existing user
			["user3", 1, 101, 10, 12, false, null], // new user
			[null, 1, 101, 10, 11, true, $nullException], // not valid user
			//New FILE, NEW model
			["user1", 4, 150, 11, 12, false, null], //existing user
			["user3", 4, 150, 11, 12, false, null], //not existing user
			[null, 4, 150, 11, 11, true, $nullException], // not validuser
			//Existing file, new MODEL
			["user1", 4, 101, 11, 12, false, null], // Existing user
			["user3", 4, 101, 11, 12, false, null], // new user
			[null, 4, 101, 10, 10, true, $nullException], // not valid user
		];
	}

	public static function otherUserStilHasConnection_Provider(): array{
		return [
			[1, false],
			[10, true],
			[11, false] // non existing image
		];
	}

	public static function countImages_Provider(): array{
		return [
			[1, 5],
			[2, 5],
			[3, 0] // non existing model
		];
	}

	public static function countProcessedImages_Provider(): array{
		return [
			[1, 3],
			[2, 3],
			[3, 0] // non existing model
		];
	}

	public static function avgProcessingDuration_Provider(): array{
		return [
			[1, 123],
			[2, 173],
			[3, 0] // non existing model
		];
	}

	public static function countUserImages_Provider(): array{
		return [
			["user1", 1, false, 5],
			["user2", 1, false, 1],
			["user2", 2, false, 4],
			["user1", 3, false, 0], // non existing model
			["user3", 1, false, 0], // non existing user
			["user3", 3, false, 0], // non existing user and model
			["user1", 1, true, 3],
			["user2", 1, true, 1],
			["user2", 2, true, 3],
			["user1", 3, true, 0], // non existing model
			["user3", 1, true, 0], // non existing user
			["user3", 3, true, 0], // non existing user and model
		];
	}

	public static function findImagesWithoutFaces_Provider(): array{
		//?string $user, int $model, int $expectedCount
		return [
			["user1", 1, 2],
			["user2", 1, 0],
			["user2", 2, 1],
			[null, 2, 2],
			[null, 1, 2],
			["user1", 3, 0], // non existing model
			["user3", 1, 0], // non existing user
			["user3", 3, 0], // non existing user and model
		];
	}

	public static function findImages_Provider(): array{
		return [
			["user1", 1, 5],
			["user2", 1, 1],
			["user2", 2, 4],
			["user1", 3, 0], // non existing model
			["user3", 1, 0], // non existing user
			["user3", 3, 0], // non existing user and model
		];
	}

	public static function findFromPerson_Provider(): array{
		return [
			["user1", 1, "Alice", null, null, 1],
			["user1", 1, "alice", null, null, 0],
			["user2", 1, "Alice", null, null, 0],
			["user2", 2, "Alice", null, null, 0],
			["user2", 2, "Bob", null, null, 2],
			["user2", 2, "Bob", 1, 1, 1],
			["user2", 2, "Bob", 0, 1, 1],
			["user1", 3, "Alice", null, null, 0], // non existing model
			["user3", 1, "Alice", null, null, 0], // non existing user
			["user3", 3, "Alice", null, null, 0], // non existing user and model
		];
	}

	public static function countFromPerson_Provider(): array{
		return [
			["user1", 1, "Alice", 1],
			["user1", 1, "alice", 0],
			["user2", 1, "Alice", 0],
			["user2", 2, "Alice", 0],
			["user2", 2, "Bob", 2],
			["user1", 3, "Alice", 0], // non existing model
			["user3", 1, "Alice", 0], // non existing user
			["user3", 3, "Alice", 0], // non existing user and model
		];
	}

	public static function imageProcessed_Provider(): array{
		//First face to test single face
		$face1 = new Face();
		$face1->person = null;
		$face1->creationTime = new \DateTime();
		$face1->x = 10;
		$face1->y = 20;
		$face1->width = 30;
		$face1->height = 40;
		$face1->confidence = 0.95;
		//Second face to test multiple faces
		$face2 = new Face();
		$face2->person = null;
		$face2->creationTime = new \DateTime();
		$face2->x = 15;
		$face2->y = 25;
		$face2->width = 35;
		$face2->height = 45;
		$face2->confidence = 0.85;
		//Wrong face to test multiple faces
		$wrongface = new Face();
		$wrongface->person = null;
		$wrongface->creationTime = new \DateTime();
		$wrongface->x = 15;
		$wrongface->y = 25;
		$wrongface->width = 35;
		$wrongface->height = 45;
		//Exception to test error handling
		$exceptionToImage = new Exception("Test exception");
		$exceptionWrongImage = new Exception("An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`nextcloud_db`.`oc_facerecog_faces`, CONSTRAINT `FK_222E69C83DA5256D` FOREIGN KEY (`image_id`) REFERENCES `oc_facerecog_images` (`id`) ON DELETE CASCADE)");
		$exceptionWrongFace = new Exception("An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'confidence' cannot be null");
		return [
			//Single image
			[5, [], 100, null, 14],
			[6, [], 100, $exceptionToImage, 14],
			[5, [$face1], 100, null, 15],
			[6, [$face1], 100, $exceptionToImage, 15],
			[5, [$face1, $face2], 100, null, 16],
			[6, [$face1, $face2], 100, $exceptionToImage, 16],
			//Shared image
			[10, [], 100, null, 9],
			[10, [], 100, $exceptionToImage, 9],
			[10, [$face1], 100, null, 10],
			[10, [$face1], 100, $exceptionToImage, 10],
			[10, [$face1, $face2], 100, null, 11],
			[10, [$face1, $face2], 100, $exceptionToImage, 11],
			//ExpectedExteption
			[100, [$face1], 100, null, 14, $exceptionWrongImage], //non existing image
			[100, [$face1], 100, $exceptionToImage, 14, $exceptionWrongImage], //non existing image
			[100, [$wrongface], 100, null, 14, $exceptionWrongFace], //non existing image
			[100, [$wrongface], 100, $exceptionToImage, 14, $exceptionWrongFace], //non existing image
		];
	}

	public static function deleteUserModel_Provider(): array{
		return [
			["user1", 1, 6, 6],
			["user1", 2, 10, 9],
			["user2", 1, 10, 10],
			["user2", 2, 7, 6],
			["user1", 3, 11, 10], //not existing model
			["user3", 1, 11, 10], //not existing user
			["user3", 3, 11, 10], //not existing user and model
		];
	}

	public static function update_Provider(): array{
		return [
			[1, "user1", 1, 101], //no update
			[1, "user1", 2, 101], //model update
			[1, "user2", 1, 101], //user update
			[1, "user2", 2, 101], //user and model update
			[1, "user1", 1, 102], //file update
			[1, "user1", 2, 102], //model and file update
			[1, "user2", 1, 102], //user and file update
			[1, "user2", 2, 102], //user and model and file update
		];
	}

	public static function delete_Provider(): array{
		return [
			[1, "user1", 1, 101, 9, 10],
			[2, "user2", 2, 102, 9, 10],
			[10, "user1", 1, 201, 9, 9], //shared image
			[10, "user2", 1, 201, 9, 9], //shared image
			[100, "user3", 1, 101, 10, 11], //not existing imageId
		];
	}
}

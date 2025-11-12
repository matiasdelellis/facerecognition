<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

use OCP\IDBConnection;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

/**
 * MIIGRATE TO V0.9.8
 * - Step1: Create new tables and duplicate data into those
 * Step2: Remove duplications from images, and faces -> Remove original columns
 */
class Version001000Date20250611141100 extends SimpleMigrationStep {

	private $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();

		//Start with table and column renaming
		if ($schema->hasTable('facerecog_faces')) {
			$faceTable = $schema->getTable('facerecog_faces');
			if ($faceTable->hasColumn('image')){
				$this->connection->executeStatement('ALTER TABLE `*PREFIX*facerecog_faces` RENAME COLUMN `image` TO `image_id`;');
			}
        }
		
		if ($schema->hasTable('facerecog_images')) {
			$faceTable = $schema->getTable('facerecog_images');
			if ($faceTable->hasColumn('file')){
				$this->connection->executeStatement('ALTER TABLE `*PREFIX*facerecog_images` RENAME COLUMN `file` TO `nc_file_id`;');
			}
        }

		if ($schema->hasTable('facerecog_persons')) {
			$this->connection->executeStatement('ALTER TABLE `*PREFIX*facerecog_persons` RENAME TO `*PREFIX*facerecog_clusters`;');
		}
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$output->warning("DO NOT STOP the migration process!");
		$output->warning("This might take a while depending on how many images you have");
		$schema = $schemaClosure();

		$faceTable = $schema->getTable('facerecog_faces');
		$faceIdOptions = $faceTable->getColumn('id')->toArray();
		unset($faceIdOptions['name']);
		unset($faceIdOptions['autoincrement']);
		$faceGroupableOptions = $faceTable->getColumn('is_groupable')->toArray();
		unset($faceGroupableOptions['name']);
		unset($faceGroupableOptions['autoincrement']);

		$clusterTable = $schema->getTable('facerecog_clusters');
		$clustersIdOptions = $clusterTable->getColumn('id')->toArray();
		unset($clustersIdOptions['name']);
		unset($clustersIdOptions['autoincrement']);

		$imageTable = $schema->getTable('facerecog_images');
		$imageIdOptions = $imageTable->getColumn('id')->toArray();
		unset($imageIdOptions['name']);
		unset($imageIdOptions['autoincrement']);

		if (!$schema->hasTable('facerecog_user_images')) {
			$table = $schema->createTable('facerecog_user_images');
			$table->addColumn('image_id', $imageIdOptions['type']->getName(), $imageIdOptions);
			$table->addColumn('user', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['image_id', 'user']);
			
			//Set Foreign key
			$table->addForeignKeyConstraint(
				'*PREFIX*facerecog_images', // Remote Table
				['image_id'], // Local Columns
				['id'], // Remote Columns
				[
					'onDelete' => 'CASCADE',
					'name' => 'fk_user_images_image_id'
				]
			);
        }
        if (!$schema->hasTable('facerecog_cluster_faces')) {
			$table = $schema->createTable('facerecog_cluster_faces');
			$table->addColumn('cluster_id', $clustersIdOptions['type']->getName(), $clustersIdOptions);
			$table->addColumn('face_id', $faceIdOptions['type']->getName(), $faceIdOptions);
			$table->addColumn('is_groupable', $faceGroupableOptions['type']->getName(), $faceGroupableOptions);
			$table->setPrimaryKey(['cluster_id', 'face_id'], "primaryKeys");

			//Set Foreign keys
			$table->addForeignKeyConstraint(
				'*PREFIX*facerecog_faces', // Remote Table
				['face_id'], // Local Columns
				['id'], // Remote Columns
				[
					'onDelete' => 'CASCADE',
					'name' => 'fk_face_id'
				]
			);
			$table->addForeignKeyConstraint(
				'*PREFIX*facerecog_clusters', // Remote Table
				['cluster_id'], // Local Columns
				['id'], // Remote Columns
				[
					'onDelete' => 'CASCADE',
					'name' => 'fk_cluster_id'
				]
			);
        }
		if (!$schema->hasTable('facerecog_persons')) {
			$table = $schema->createTable('facerecog_persons');
			$table->addColumn('id', 'integer', [
				'autoincrement'=>true,
				'unsigned'=>true
			]);
			$table->addColumn('name', 'string', [
				'notnull' => false,
				'length'=> 128
			]);
			$table->addColumn('is_shared', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name'], 'UNIQ_name');
		}

		$personsTable = $schema->getTable('facerecog_persons');
		$personsOptions = $personsTable->getColumn('id')->toArray();
		unset($personsOptions['name']);
		unset($personsOptions['autoincrement']);

		if (!$schema->hasTable('facerecog_person_clusters')) {
			$table = $schema->createTable('facerecog_person_clusters');
			$table->addColumn('cluster_id', $clustersIdOptions['type']->getName(), $clustersIdOptions);
			$table->addColumn('person_id', $personsOptions['type']->getName(), $personsOptions);
			$table->setPrimaryKey(['cluster_id', 'person_id'], "primaryKeys");

			//Set Foreign keys
			$table->addForeignKeyConstraint(
				'*PREFIX*facerecog_persons', // Remote Table
				['person_id'], // Local Columns
				['id'], // Remote Columns
				[
					'onDelete' => 'CASCADE',
					'name' => 'fk_person_id'
				]
			);
			$table->addForeignKeyConstraint(
				'*PREFIX*facerecog_clusters', // Remote Table
				['cluster_id'], // Local Columns
				['id'], // Remote Columns
				[
					'onDelete' => 'CASCADE',
					'name' => 'fk_cluster_id'
				]
			);
        }

		if ($schema->hasTable('facerecog_faces')) {
			$table = $schema->getTable('facerecog_faces');

			//Set Foreign keys
			$table->addForeignKeyConstraint(
				'*PREFIX*facerecog_images', // Remote Table
				['image_id'], // Local Columns
				['id'], // Remote Columns
				[
					'onDelete' => 'CASCADE',
					'name' => 'fk_image_id'
				]
			);
        }
		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->warning("Connecting images with users, persons with faces and persons with persons");
		$this->migratePersons();
		$this->connectImagesWithUsers();
		$this->connectClustersWithFaces();
		$this->connectClusterWithPersons();
	}

	private function migratePersons() {
		
		//Migrate to persons to dedicated tabloe connection
		//Needs to support the shared persons
		$insertPersons = $this->connection->getQueryBuilder();
		$insertPersons
			->insert('facerecog_persons')
			->values([
				'name' => '?'
			]);

		$queryClusters = $this->connection->getQueryBuilder();
		$queryClusters->selectDistinct('name')
			->from('facerecog_clusters', 'c')
			->Where($queryClusters->expr()->isNotNull('c.name'));

		$resultClusters = $queryClusters->executeQuery();
		while ($row = $resultClusters->fetch()) {
			$insertPersons->setParameters([
				$row['name']
			]);
			$insertPersons->executeStatement();
		}
		$resultClusters->closeCursor();
	}

	private function connectClusterWithPersons() {
		//Migrate persons - Person connection
		//Needs to support the shared persons
		$insertPersons = $this->connection->getQueryBuilder();
		$insertPersons
			->insert('facerecog_person_clusters')
			->values([
				'cluster_id' => '?',
				'person_id' => '?',
			]);

		//GetPersons with names
		$queryClusters = $this->connection->getQueryBuilder();
		$queryClusters->select('id', 'name')
			->from('facerecog_clusters', 'c')
			->Where($queryClusters->expr()->isNotNull('c.name'));

		$resultQueryClusters = $queryClusters->executeQuery();

		while ($row = $resultQueryClusters->fetch()) {
			//Get NameId for Person
			$queryPersons = $this->connection->getQueryBuilder();
			$queryPersons->select('id', 'name')
				->from('facerecog_persons', 'p')
				->Where($queryPersons->expr()->eq('name', $queryPersons->createNamedParameter($row['name'])));

			$resultPersons = $queryPersons->executeQuery();
			while ($PersonsRow = $resultPersons->fetch()) {
				$insertPersons->setParameters([
					$row['id'],
					$PersonsRow['id']
				]);
				$insertPersons ->executeStatement();
			}
			$resultPersons->closeCursor();
		}

		$resultQueryClusters->closeCursor();
	}
	
	private function connectClustersWithFaces(){
		//Migrate to personsFaces N2N connection
		//Needs to support the shared files, record but different person groups
		$insertPersonFace = $this->connection->getQueryBuilder();
		$insertPersonFace
			->insert('facerecog_cluster_faces')
			->values([
				'cluster_id' => '?',
				'face_id' => '?',
				'is_groupable' => '?'
			]);

		$queryFaces = $this->connection->getQueryBuilder();
		$queryFaces->select('person','id', 'is_groupable')
		->from('facerecog_faces')
			->Where($queryFaces->expr()->isNotNull('person'));;

		$resultFaces = $queryFaces->executeQuery();
		while ($row = $resultFaces->fetch()) {
			$insertPersonFace->setParameters([
				$row['person'],
				$row['id'],
				$row['is_groupable'],
			]);
			$insertPersonFace->executeStatement();
		}
		$resultFaces->closeCursor();
	}

	private function connectImagesWithUsers(){
		//Migrate to userImages N2N connection
		//Needs to support the shared files, record but different person groups
		$insertUserImages = $this->connection->getQueryBuilder();
		$insertUserImages
			->insert('facerecog_user_images')
			->values([
				'image_id' => '?',
				'user' => '?'
			]);

		$queryImages = $this->connection->getQueryBuilder();
		$queryImages->select('user','id')->from('facerecog_images');

		$resultImages = $queryImages->executeQuery();
		while ($row = $resultImages->fetch()) {
			$insertUserImages->setParameters([
				$row['id'],
				$row['user']
			]);
			$insertUserImages->executeStatement();
		}
		$resultImages->closeCursor();
	}
}

<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version000509Date20191204003814 extends SimpleMigrationStep {

	/** @var IDBConnection */
	protected $connection;

	/**
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param IOutput $output
	 * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('face_recognition_persons')) {
			return;
		}

		/*
		 * Migrate 'face_recognition_persons' to 'facerecog_persons'
		 */
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from('face_recognition_persons');

		$insert = $this->connection->getQueryBuilder();
		$insert->insert('facerecog_persons')
			->values([
				'id' => '?',
				'user' => '?',
				'name' => '?',
				'is_valid' => '?',
				'last_generation_time' => '?',
				'linked_user' => '?'
			]);

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$insert->setParameters([
				$row['id'],
				$row['user'],
				$row['name'],
				$row['is_valid'],
				$row['last_generation_time'],
				$row['linked_user']
			])->execute();
		}
		$result->closeCursor();

		/*
		 * Migrate 'face_recognition_images' to 'facerecog_images'
		 */
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from('face_recognition_images');

		$insert = $this->connection->getQueryBuilder();
		$insert->insert('facerecog_images')
			->values([
				'id' => '?',
				'user' => '?',
				'file' => '?',
				'model' => '?',
				'is_processed' => '?',
				'error' => '?',
				'last_processed_time' => '?',
				'processing_duration' => '?'
			]);

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$insert->setParameters([
				$row['id'],
				$row['user'],
				$row['file'],
				$row['model'],
				$row['is_processed'],
				$row['error'],
				$row['last_processed_time'],
				$row['processing_duration']
			])->execute();
		}
		$result->closeCursor();

		/*
		 * Migrate 'face_recognition_faces' to 'facerecog_faces'
		 */
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from('face_recognition_faces');

		$insert = $this->connection->getQueryBuilder();
		$insert->insert('facerecog_faces')
			->values([
				'id' => '?',
				'image' => '?',
				'person' => '?',
				'left' => '?',
				'right' => '?',
				'top' => '?',
				'bottom' => '?',
				'descriptor' => '?',
				'creation_time' => '?',
				'confidence' => '?',
				'landmarks' => '?'
			]);

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$insert->setParameters([
				$row['id'],
				$row['image'],
				$row['person'],
				$row['left'],
				$row['right'],
				$row['top'],
				$row['bottom'],
				$row['descriptor'],
				$row['creation_time'],
				$row['confidence'],
				$row['landmarks']
			])->execute();
		}
		$result->closeCursor();

		/*
		 * Migrate 'face_recognition_face_models' to 'facerecog_models'
		 */
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from('face_recognition_face_models');

		$insert = $this->connection->getQueryBuilder();
		$insert->insert('facerecog_models')
			->values([
				'id' => '?',
				'name' => '?',
				'description' => '?'
			]);

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$insert->setParameters([
				$row['id'],
				$row['name'],
				$row['description']
			])->execute();
		}
		$result->closeCursor();

	}

	/**
	 * @param IOutput $output
	 * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('face_recognition_persons')) {
			$schema->dropTable('face_recognition_persons');
		}
		if ($schema->hasTable('face_recognition_images')) {
			$schema->dropTable('face_recognition_images');
		}
		if ($schema->hasTable('face_recognition_faces')) {
			$schema->dropTable('face_recognition_faces');
		}
		if ($schema->hasTable('face_recognition_face_models')) {
			$schema->dropTable('face_recognition_face_models');
		}

		return $schema;
	}

}

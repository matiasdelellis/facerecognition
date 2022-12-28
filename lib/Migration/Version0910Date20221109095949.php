<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

use OCP\IDBConnection;

class Version0910Date20221109095949 extends SimpleMigrationStep {

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
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$table = $schema->getTable('facerecog_faces');
		if ($table->hasColumn('width'))
			return null;

		/**
		 * NOTE: These columns should just be notnull.
		 * In this migration add an default, since the previous rows would be null and cannot be migrated.
		 */
		$table->addColumn('x', 'integer', [
			'notnull' => true,
			'default' => -1,
			'length' => 4,
		]);
		$table->addColumn('y', 'integer', [
			'notnull' => true,
			'default' => -1,
			'length' => 4,
		]);
		$table->addColumn('width', 'integer', [
			'notnull' => true,
			'default' => -1,
			'length' => 4,
		]);
		$table->addColumn('height', 'integer', [
			'notnull' => true,
			'default' => -1,
			'length' => 4,
		]);

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$update = $this->connection->getQueryBuilder();
		$update->update('facerecog_faces')
			->set('x', $update->createParameter('new_x'))
			->set('y', $update->createParameter('new_y'))
			->set('width', $update->createParameter('new_width'))
			->set('height', $update->createParameter('new_height'))
			->where($update->expr()->eq('id', $update->createParameter('id')));

		$query = $this->connection->getQueryBuilder();
		$query->select('*')->from('facerecog_faces');

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$update->setParameter('new_x', $row['left']);
			$update->setParameter('new_y', $row['top']);
			$update->setParameter('new_width', $row['right'] - $row['left']);
			$update->setParameter('new_height', $row['bottom'] - $row['top']);
			$update->setParameter('id', $row['id']);
			$update->executeStatement();
		}
		$result->closeCursor();
	}

}

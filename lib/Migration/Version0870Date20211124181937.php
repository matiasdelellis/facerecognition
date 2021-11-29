<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

use OCP\IDBConnection;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version0870Date20211124181937 extends SimpleMigrationStep {

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
		if (!$table->hasColumn('is_groupable')) {
			$table->addColumn('is_groupable', 'boolean', [
				'notnull' => false,
				'default' => true,
			]);
		}
		$table = $schema->getTable('facerecog_persons');
		if (!$table->hasColumn('is_visible')) {
			$table->addColumn('is_visible', 'boolean', [
				'notnull' => false,
				'default' => true,
			]);
		}
		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

}

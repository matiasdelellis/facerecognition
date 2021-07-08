<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version0820Date20210708130230 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->ensureColumnIsNullable($schema, 'facerecog_persons', 'is_valid');
		$this->ensureColumnIsNullable($schema, 'facerecog_images', 'is_processed');

		return null;
	}

	protected function ensureColumnIsNullable(ISchemaWrapper $schema, string $tableName, string $columnName): bool {
		$table = $schema->getTable($tableName);
		$column = $table->getColumn($columnName);

		if ($column->getNotnull()) {
			$column->setNotnull(false);
			return true;
		}

		return false;
	}

}

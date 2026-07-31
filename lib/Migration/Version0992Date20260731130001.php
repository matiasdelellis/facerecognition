<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Leaves facerecog_persons with what a person is: a name of a user. The model
 * and the visibility belong to the cluster, and were moved with them by the
 * previous step.
 */
class Version0992Date20260731130001 extends SimpleMigrationStep {

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
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('facerecog_persons');

		if ($table->hasIndex('persons_user_model_idx')) {
			$table->dropIndex('persons_user_model_idx');
		}

		foreach (['model', 'is_visible'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
			}
		}

		if (!$table->hasIndex('persons_user_name_idx')) {
			$table->addIndex(['user', 'name'], 'persons_user_name_idx');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}

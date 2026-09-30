<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The regions of a photo the user asked to be searched for faces again. They
 * are searched by the background job, like everything that runs the model, so
 * they wait here until the next run, and keep what that run found.
 *
 * The user, model and file of a region are the ones of its image, as for a
 * face, so there are no columns of their own for them.
 */
class Version0997Date20260923000001 extends SimpleMigrationStep {

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

		if (!$schema->hasTable('facerecog_manual_regions')) {
			$table = $schema->createTable('facerecog_manual_regions');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('image', 'bigint', [
				'notnull' => true,
				'length' => 8,
			]);
			foreach (['x', 'y', 'width', 'height'] as $column) {
				$table->addColumn($column, 'integer', [
					'notnull' => true,
					'length' => 4,
				]);
			}
			$table->addColumn('state', 'string', [
				'notnull' => true,
				'length' => 16,
				'default' => 'pending',
			]);
			foreach (['found_count', 'too_small_count', 'low_confidence_count'] as $column) {
				$table->addColumn($column, 'integer', [
					'notnull' => true,
					'length' => 4,
					'default' => 0,
				]);
			}
			$table->addColumn('error', 'string', [
				'notnull' => false,
				'length' => 1024,
			]);
			$table->addColumn('creation_time', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('processed_time', 'datetime', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
			// The background job looks for the pending ones, and the photo
			// view for the ones of a single image.
			$table->addIndex(['state'], 'manual_regions_state_idx');
			$table->addIndex(['image'], 'manual_regions_image_idx');
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

<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version000509Date20191203003814 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return void
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('facerecog_models')) {
			$table = $schema->createTable('facerecog_models');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 256,
			]);
			$table->addColumn('description', 'string', [
				'notnull' => false,
				'length' => 1024,
			]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('facerecog_persons')) {
			$table = $schema->createTable('facerecog_persons');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 256,
			]);
			$table->addColumn('is_valid', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('last_generation_time', 'datetime', [
				'notnull' => false,
			]);
			$table->addColumn('linked_user', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user'], 'persons_user_idx');
		}

		if (!$schema->hasTable('facerecog_images')) {
			$table = $schema->createTable('facerecog_images');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('user', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('file', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('model', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('is_processed', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('error', 'string', [
				'notnull' => false,
				'length' => 1024,
			]);
			$table->addColumn('last_processed_time', 'datetime', [
				'notnull' => false,
			]);
			$table->addColumn('processing_duration', 'bigint', [
				'notnull' => false,
				'length' => 8,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user'], 'images_user_idx');
			$table->addIndex(['file'], 'images_file_idx');
			$table->addIndex(['model'], 'images_model_idx');
		}

		if (!$schema->hasTable('facerecog_faces')) {
			$table = $schema->createTable('facerecog_faces');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('image', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('person', 'integer', [
				'notnull' => false,
				'length' => 4,
			]);
			$table->addColumn('left', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('right', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('top', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('bottom', 'integer', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('confidence', 'float', [
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('landmarks', 'json', [
				'notnull' => true,
			]);
			$table->addColumn('descriptor', 'json', [
				'notnull' => true,
			]);
			$table->addColumn('creation_time', 'datetime', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['image'], 'faces_image_idx');
			$table->addIndex(['person'], 'faces_person_idx');
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
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}
}

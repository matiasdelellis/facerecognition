<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;

use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The analysis happens in two passes: a fast one with the HOG model on a small
 * image, and a refinement one with the current model at maximum resolution that
 * replaces the faces of each image. The is_refined column tells whether an image
 * already went through the second pass, so that a refinement run can pick up
 * exactly the images that still have the fast-pass faces.
 */
class Version0995Date20260801000000 extends SimpleMigrationStep {

	/** @var IDBConnection */
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
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('facerecog_images');

		if (!$table->hasColumn('is_refined')) {
			$table->addColumn('is_refined', 'boolean', [
				'notnull' => true,
				'default' => false,
			]);
		}

		return $schema;
	}

	/**
	 * The images that were analyzed before this version already went through the
	 * model at the configured quality, so they are considered refined. Only the
	 * images of the fast pass have to be taken again.
	 *
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$updated = $qb->update('facerecog_images')
			->set('is_refined', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('is_processed', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeStatement();

		$output->info(sprintf('Marked %d already processed images as refined', $updated));
	}
}

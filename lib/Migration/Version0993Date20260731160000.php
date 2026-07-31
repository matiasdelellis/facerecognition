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
 * The face of an image belongs to a cluster, not to a person: the person is who
 * the user says that cluster is, and it has as many clusters as ways their face
 * was found. The column said person since the time both were the same row.
 *
 * This adds the column with the right name and copies the values. The old one is
 * dropped by the next step, once nothing reads it any more.
 */
class Version0993Date20260731160000 extends SimpleMigrationStep {

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
		$table = $schema->getTable('facerecog_faces');

		if (!$table->hasColumn('cluster')) {
			$table->addColumn('cluster', 'integer', [
				'notnull' => false,
				'length' => 4,
			]);
		}

		if (!$table->hasIndex('faces_cluster_idx')) {
			$table->addIndex(['cluster'], 'faces_cluster_idx');
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
		$qb = $this->connection->getQueryBuilder();
		$updated = $qb->update('facerecog_faces')
			->set('cluster', $qb->createFunction($qb->getColumnName('person')))
			->where($qb->expr()->isNotNull('person'))
			->executeStatement();

		$output->info(sprintf('Moved %d faces to the cluster column', $updated));
	}
}

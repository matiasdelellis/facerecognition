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
 * A row of facerecog_persons is a cluster, and it now says which model it
 * belongs to instead of having every query ask the faces about it.
 *
 * Three columns are dropped along the way:
 *
 *  is_valid              nothing invalidates a cluster any more, since the
 *                        clustering grows the clusters instead of rebuilding
 *                        them. Left as it was, the only thing it could still do
 *                        is hide a cluster from its user.
 *  last_generation_time  was only ever written.
 *  linked_user           was only ever written, always as null.
 */
class Version0990Date20260731120000 extends SimpleMigrationStep {

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
		$table = $schema->getTable('facerecog_persons');

		if (!$table->hasColumn('model')) {
			// Filled in postSchemaChange for the clusters that already exist.
			$table->addColumn('model', 'integer', [
				'notnull' => false,
				'length' => 4,
			]);
		}

		if (!$table->hasIndex('persons_user_model_idx')) {
			$table->addIndex(['user', 'model'], 'persons_user_model_idx');
		}

		if ($table->hasIndex('persons_user_idx')) {
			// Covered by the one above, which starts with the same column.
			$table->dropIndex('persons_user_idx');
		}

		foreach (['is_valid', 'last_generation_time', 'linked_user'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
			}
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
		// The model of a cluster is the one of the images its faces are in.
		$select = $this->connection->getQueryBuilder();
		$select->selectDistinct('f.person')
			->addSelect('i.model')
			->from('facerecog_faces', 'f')
			->innerJoin('f', 'facerecog_images', 'i', $select->expr()->eq('f.image', 'i.id'))
			->where($select->expr()->isNotNull('f.person'));

		$result = $select->executeQuery();
		$clustersByModel = [];
		while ($row = $result->fetch()) {
			$clustersByModel[(int) $row['model']][] = (int) $row['person'];
		}
		$result->closeCursor();

		$update = $this->connection->getQueryBuilder();
		$update->update('facerecog_persons')
			->set('model', $update->createParameter('model'))
			->where($update->expr()->in('id', $update->createParameter('ids')));

		$updated = 0;
		foreach ($clustersByModel as $model => $clusters) {
			foreach (array_chunk($clusters, 1000) as $chunk) {
				$update->setParameter('model', $model, IQueryBuilder::PARAM_INT);
				$update->setParameter('ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
				$updated += $update->executeStatement();
			}
		}
		$output->info(sprintf('Assigned the model to %d clusters', $updated));

		// A cluster without faces has no model to take, and it is deleted on
		// every run of the clustering anyway.
		$delete = $this->connection->getQueryBuilder();
		$deleted = $delete->delete('facerecog_persons')
			->where($delete->expr()->isNull('model'))
			->executeStatement();

		if ($deleted > 0) {
			$output->info(sprintf('Deleted %d clusters that had no faces', $deleted));
		}
	}
}

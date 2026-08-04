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
 * Splits what was one table in two, because they were two things:
 *
 *   facerecog_clusters   a set of faces that look alike
 *   facerecog_persons    somebody with a name
 *
 * A person is several clusters and not one, since the same face at another age,
 * from another angle or with the beard shaved does not land together. That was
 * already the case before this, only the person was the name repeated in every
 * cluster row: renaming meant updating all of them, listing them meant one
 * query per name, and two people called the same were one.
 *
 * The clusters are moved to the new table and get new ids, so the faces are
 * repointed here. The old table keeps one row per name, which is now the
 * person, and the clusters point at it.
 */
class Version0992Date20260731130000 extends SimpleMigrationStep {

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

		if (!$schema->hasTable('facerecog_clusters')) {
			$table = $schema->createTable('facerecog_clusters');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 4,
			]);
			$table->addColumn('user', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('model', 'integer', [
				'notnull' => false,
				'length' => 4,
			]);
			$table->addColumn('is_visible', 'boolean', [
				'notnull' => false,
				'default' => true,
			]);
			// The person this cluster is one of the looks of, if the user said.
			$table->addColumn('person', 'integer', [
				'notnull' => false,
				'length' => 4,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user', 'model'], 'clusters_user_model_idx');
			$table->addIndex(['person'], 'clusters_person_idx');
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
		$clusters = $this->readOldClusters();
		if (empty($clusters)) {
			$output->info('There were no clusters to move');
			return;
		}

		$newIdOf = $this->insertClusters($clusters);
		$output->info(sprintf('Moved %d clusters to facerecog_clusters', count($newIdOf)));

		$faces = $this->repointFaces($newIdOf);
		$output->info(sprintf('Repointed %d faces', $faces));

		$persons = $this->buildPersons($clusters, $newIdOf);
		$output->info(sprintf('Kept %d persons out of %d named clusters', $persons['persons'], $persons['named']));
	}

	/**
	 * @return array [['id' => int, 'user' => string, 'model' => ?int,
	 *  'is_visible' => bool, 'name' => ?string], ...]
	 */
	private function readOldClusters(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'user', 'model', 'is_visible', 'name')
			->from('facerecog_persons')
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$clusters = [];
		while ($row = $result->fetch()) {
			$clusters[] = [
				'id' => (int) $row['id'],
				'user' => (string) $row['user'],
				'model' => is_null($row['model']) ? null : (int) $row['model'],
				'is_visible' => (bool) $row['is_visible'],
				'name' => $row['name'],
			];
		}
		$result->closeCursor();

		return $clusters;
	}

	/**
	 * @return array [oldId => newId]
	 */
	private function insertClusters(array $clusters): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('facerecog_clusters')
			->values([
				'user' => $qb->createParameter('user'),
				'model' => $qb->createParameter('model'),
				'is_visible' => $qb->createParameter('is_visible'),
			]);

		$newIdOf = [];
		foreach ($clusters as $cluster) {
			$qb->setParameter('user', $cluster['user']);
			$qb->setParameter('model', $cluster['model'], IQueryBuilder::PARAM_INT);
			$qb->setParameter('is_visible', $cluster['is_visible'], IQueryBuilder::PARAM_BOOL);
			$qb->executeStatement();
			$newIdOf[$cluster['id']] = $qb->getLastInsertId();
		}

		return $newIdOf;
	}

	/**
	 * The new ids come from another sequence and can collide with the old ones
	 * still in the column, so they are written negated first and flipped at the
	 * end. Ids are positive, so a negative one is unambiguously already done.
	 *
	 * @return int Faces repointed
	 */
	private function repointFaces(array $newIdOf): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('facerecog_faces')
			->set('person', $qb->createParameter('new_id'))
			->where($qb->expr()->eq('person', $qb->createParameter('old_id')));

		foreach ($newIdOf as $oldId => $newId) {
			$qb->setParameter('new_id', -$newId, IQueryBuilder::PARAM_INT);
			$qb->setParameter('old_id', $oldId, IQueryBuilder::PARAM_INT);
			$qb->executeStatement();
		}

		$flip = $this->connection->getQueryBuilder();
		return $flip->update('facerecog_faces')
			->set('person', $flip->createFunction('-' . $flip->getColumnName('person')))
			->where($flip->expr()->lt('person', $flip->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Leaves one row per name in facerecog_persons and points the clusters that
	 * carried that name at it.
	 *
	 * @return array ['persons' => int, 'named' => int]
	 */
	private function buildPersons(array $clusters, array $newIdOf): array {
		$named = 0;
		$clustersOfPerson = [];
		$canonicalOf = [];

		foreach ($clusters as $cluster) {
			$name = $cluster['name'];
			if (is_null($name) || $name === '') {
				continue;
			}
			$named++;

			$key = $cluster['user'] . "\0" . $name;
			if (!isset($canonicalOf[$key])) {
				// The clusters were read by id, so this is the oldest of them.
				$canonicalOf[$key] = $cluster['id'];
			}
			$clustersOfPerson[$canonicalOf[$key]][] = $newIdOf[$cluster['id']];
		}

		$update = $this->connection->getQueryBuilder();
		$update->update('facerecog_clusters')
			->set('person', $update->createParameter('person'))
			->where($update->expr()->in('id', $update->createParameter('ids')));

		foreach ($clustersOfPerson as $personId => $clusterIds) {
			foreach (array_chunk($clusterIds, 1000) as $chunk) {
				$update->setParameter('person', $personId, IQueryBuilder::PARAM_INT);
				$update->setParameter('ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
				$update->executeStatement();
			}
		}

		// What is left of the old table is the persons: the rows that were not
		// named were only clusters, and the repeated names are now one row.
		$keep = array_values($canonicalOf);
		$delete = $this->connection->getQueryBuilder();
		$delete->delete('facerecog_persons');
		if (!empty($keep)) {
			$delete->where($delete->expr()->notIn('id', $delete->createNamedParameter($keep, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$delete->executeStatement();

		return ['persons' => count($keep), 'named' => $named];
	}
}

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
 * A face the user marked by hand goes through a search for a descriptor in the
 * marked region, and manual_state keeps how that search ended. It is null for
 * every face that is not such a marking: the ones the analysis found, and the
 * ones the user only moved to another person, which carry is_manual too, only
 * to keep the analysis from replacing them.
 *
 * box_adjusted says that the search put the box of a marking somewhere else
 * than where it was drawn.
 *
 * The markings that already exist are searched again. Their confidence is the
 * 1.0 they were created with, before any detector ever looked at them, and it
 * lifted the minimum confidence for every one of them. Searching them again
 * replaces it with the value the detector really gives. That includes the
 * markings the user did not flag for recognition back then, which were never
 * searched at all.
 */
class Version0997Date20260923000000 extends SimpleMigrationStep {

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
		if (!$table->hasColumn('manual_state')) {
			$table->addColumn('manual_state', 'string', [
				'notnull' => false,
				'length' => 16,
				'default' => null,
			]);
		}
		if (!$table->hasColumn('box_adjusted')) {
			$table->addColumn('box_adjusted', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
		}
		return $schema;
	}

	/**
	 * Puts every existing marking back to pending, and makes it groupable
	 * again so that the search can pick it up: a marking whose search failed,
	 * or that was never flagged for it, was left non-groupable.
	 *
	 * A marking and a moved face both carry is_manual. What tells them apart is
	 * the landmarks: a marking was stored with none, and the search never
	 * filled them in, while every face the analysis found has them. A moved
	 * face keeps its is_groupable as it is, since that is the decision of the
	 * user to take it out of its group.
	 *
	 * The landmarks are checked here and not in the query, since the column is
	 * json and every database compares it in its own way.
	 *
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'landmarks')
			->from('facerecog_faces')
			->where($select->expr()->eq('is_manual', $select->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($select->expr()->isNull('manual_state'));

		$result = $select->executeQuery();
		$markings = [];
		while ($row = $result->fetch()) {
			if (self::hasNoLandmarks($row['landmarks'])) {
				$markings[] = (int) $row['id'];
			}
		}
		$result->closeCursor();

		$update = $this->connection->getQueryBuilder();
		$update->update('facerecog_faces')
			->set('manual_state', $update->createNamedParameter('pending'))
			->set('is_groupable', $update->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($update->expr()->in('id', $update->createParameter('ids')));

		foreach (array_chunk($markings, 1000) as $chunk) {
			$update->setParameter('ids', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
			$update->executeStatement();
		}

		$output->info(sprintf('Queued %d manually marked faces to be searched for a descriptor again', count($markings)));
	}

	/**
	 * @param mixed $landmarks the json of the column, as the database returns it
	 */
	private static function hasNoLandmarks($landmarks): bool {
		if (is_resource($landmarks)) {
			$landmarks = stream_get_contents($landmarks);
		}
		$decoded = json_decode((string) $landmarks, true);
		return is_array($decoded) && count($decoded) === 0;
	}
}

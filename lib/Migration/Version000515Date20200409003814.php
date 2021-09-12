<?php
declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;

use OCP\DB\ISchemaWrapper;

use OCP\IDBConnection;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version000515Date20200409003814 extends SimpleMigrationStep {

	/** @var IDBConnection */
	protected $connection;

	/**
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

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
	 *
	 * @return void
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		$this->migratePreferencesKey('preferences', 'facerecognition', 'recreate-clusters', 'recreate_clusters');
		$this->migratePreferencesKey('preferences', 'facerecognition', 'force-create-clusters', 'force_create_clusters');

		$this->migratePreferencesKey('appconfig', 'facerecognition', 'handle-external-files', 'handle_external_files');
		$this->migratePreferencesKey('appconfig', 'facerecognition', 'handle-shared-files', 'handle_shared_files');
		$this->migratePreferencesKey('appconfig', 'facerecognition', 'min-confidence', 'min_confidence');
		$this->migratePreferencesKey('appconfig', 'facerecognition', 'show-not-grouped', 'show_not_grouped');

		$this->deletePreferencesKey('appconfig', 'facerecognition', 'memory-limits');
		$this->deletePreferencesKey('appconfig', 'facerecognition', 'queue-done');
		$this->deletePreferencesKey('appconfig', 'facerecognition', 'starttime');
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

	/**
	 * @param string $table
	 * @param string $appName
	 */
	protected function migratePreferencesKey(string $table, string $appName, string $key, string $toKey): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update($table)
			->set('configkey', $qb->createNamedParameter($toKey))
			->where($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter($appName)));
		$qb->execute();
	}

	/**
	 * @param string $table
	 * @param string $appName
	 * @param string $key
	 */
	protected function deletePreferencesKey(string $table, string $appName, string $key): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appName)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		$qb->execute();
	}

}

<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Album;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroupManager;

class AlbumMapper {

	private IDBConnection $connection;
	private ITimeFactory $timeFactory;

	public function __construct(IDBConnection $connection,
	                            ITimeFactory  $timeFactory
	) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function create(string $userId, string $name, string $location = 'Face Recognition'): int {
		$created = $this->timeFactory->getTime();
		$query = $this->connection->getQueryBuilder();
		$query->insert("photos_albums")
			->values([
				'user' => $query->createNamedParameter($userId),
				'name' => $query->createNamedParameter($name),
				'location' => $query->createNamedParameter($location),
				'created' => $query->createNamedParameter($created, IQueryBuilder::PARAM_INT),
				'last_added_photo' => $query->createNamedParameter(-1, IQueryBuilder::PARAM_INT),
			]);
		$query->executeStatement();

		return $query->getLastInsertId();
	}

	public function get(string $userId, string $name, string $location = 'Face Recognition'): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('album_id')
			->from('photos_albums')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->andWhere($qb->expr()->eq('location', $qb->createNamedParameter($location)))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));

		$id = $qb->executeQuery()->fetchOne();
		if ($id === false) {
			return -1;
		} else {
			return (int)$id;
		}
	}

	public function getAll(string $userId, string $location = 'Face Recognition'): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('name')
			->from('photos_albums')
			->where($qb->expr()->eq('location', $qb->createNamedParameter($location)))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
		$rows = $qb->executeQuery()->fetchAll();

		$result = [];
		foreach ($rows as $row) {
			$result[] = (string)$row['name'];
		}
		return $result;
	}

	public function delete(int $albumId): void {
		$this->connection->beginTransaction();

		$query = $this->connection->getQueryBuilder();
		$query->delete("photos_albums")
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();

		$query = $this->connection->getQueryBuilder();
		$query->delete("photos_albums_files")
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();

		$query = $this->connection->getQueryBuilder();
		$query->delete("photos_albums_collabs")
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();

		$this->connection->commit();
	}

	/**
	 * @param int $albumId
	 * @return int[]
	 */
	public function getFiles(int $albumId): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('file_id')
			->from('photos_albums_files')
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId)));
		$rows = $query->executeQuery()->fetchAll();

		$result = [];
		foreach ($rows as $row) {
			$result[] = (int)$row['file_id'];
		}
		return $result;
	}

	public function addFile(int $albumId, int $fileId, string $owner): void {
		$added = $this->timeFactory->getTime();
		$query = $this->connection->getQueryBuilder();
		$query->insert('photos_albums_files')
			->values([
				'album_id' => $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT),
				'file_id' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'added' => $query->createNamedParameter($added, IQueryBuilder::PARAM_INT),
				'owner' => $query->createNamedParameter($owner),
			]);
		$query->executeStatement();

		$query = $this->connection->getQueryBuilder();
		$query->update('photos_albums')
			->set('last_added_photo', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();
	}

	public function removeFile(int $albumId, int $fileId): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('photos_albums_files')
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();

		$query = $this->connection->getQueryBuilder();
		$query->update('photos_albums')
			->set('last_added_photo', $query->createNamedParameter($this->getLastAdded($albumId), IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();
	}

	private function getLastAdded(int $albumId): int {
		$query = $this->connection->getQueryBuilder();
		$query->select('file_id')
			->from('photos_albums_files')
			->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
			->orderBy('added', 'DESC')
			->setMaxResults(1);
		$id = $query->executeQuery()->fetchOne();
		if ($id === false) {
			return -1;
		} else {
			return (int)$id;
		}
	}

}

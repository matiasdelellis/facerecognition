<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The regions the user asked to be searched for faces again. The user, model
 * and file of a region are the ones of its image, as for a face.
 */
class ManualRegionMapper extends QBMapper {

	/** @var bool|null Whether the table exists, once asked */
	private $available = null;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'facerecog_manual_regions', '\OCA\FaceRecognition\Db\ManualRegion');
	}

	/**
	 * Whether the table exists. A migration creates it, and the code that
	 * uses it can be deployed before it ran: until then the regions are left
	 * out, and nothing else is. The answer is kept for the life of the mapper,
	 * so the database is asked once per run, or once per request.
	 */
	public function isAvailable(): bool {
		if ($this->available === null) {
			try {
				$this->available = $this->db->tableExists($this->getTableName());
			} catch (\Throwable $e) {
				$this->available = false;
			}
		}
		return $this->available;
	}

	/**
	 * Queues a region of an image to be searched by the next run.
	 *
	 * The rectangle is in pixels of the original photo, like the faces.
	 */
	public function enqueue(int $imageId, int $x, int $y, int $width, int $height): ManualRegion {
		$region = new ManualRegion();
		$region->setImage($imageId);
		$region->setX($x);
		$region->setY($y);
		$region->setWidth($width);
		$region->setHeight($height);
		$region->setState(ManualRegion::STATE_PENDING);
		$region->setFoundCount(0);
		$region->setTooSmallCount(0);
		$region->setLowConfidenceCount(0);
		$region->setCreationTime(new \DateTime());

		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'image' => $qb->createNamedParameter($imageId, IQueryBuilder::PARAM_INT),
				'x' => $qb->createNamedParameter($x, IQueryBuilder::PARAM_INT),
				'y' => $qb->createNamedParameter($y, IQueryBuilder::PARAM_INT),
				'width' => $qb->createNamedParameter($width, IQueryBuilder::PARAM_INT),
				'height' => $qb->createNamedParameter($height, IQueryBuilder::PARAM_INT),
				'state' => $qb->createNamedParameter(ManualRegion::STATE_PENDING),
				'found_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'too_small_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'low_confidence_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'creation_time' => $qb->createNamedParameter($region->getCreationTime(), IQueryBuilder::PARAM_DATE),
			])
			->executeStatement();

		$region->setId($qb->getLastInsertId());

		return $region;
	}

	/**
	 * The regions of the user and model that were not searched yet, oldest
	 * first, with the file of their image.
	 *
	 * @return array<int, array<string, mixed>> rows with id, image, file, x, y, width, height
	 */
	public function findPending(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.image', 'i.file', 'r.x', 'r.y', 'r.width', 'r.height')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'facerecog_images', 'i', $qb->expr()->eq('r.image', 'i.id'))
			->where($qb->expr()->eq('r.state', $qb->createNamedParameter(ManualRegion::STATE_PENDING)))
			->andWhere($qb->expr()->eq('i.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId, IQueryBuilder::PARAM_INT)))
			->orderBy('r.id', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The regions of one image, whatever their state, oldest first.
	 *
	 * @return ManualRegion[]
	 */
	public function findByImage(int $imageId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * One region, or null if there is none with that id.
	 */
	public function findById(int $regionId): ?ManualRegion {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($regionId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Deletes the given regions, whatever their state: a pending one is not
	 * searched any more. The faces a search already found are not touched.
	 *
	 * @param int[] $regionIds
	 */
	public function deleteRegions(array $regionIds): void {
		foreach (array_chunk(array_values(array_unique(array_map('intval', $regionIds))), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * Records that the region was searched, and what came out of it.
	 *
	 * @param int $found Faces created
	 * @param int $tooSmall Of those, the ones smaller than the minimum face size
	 * @param int $lowConfidence Of those, the ones below the minimum confidence
	 */
	public function markDone(int $regionId, int $found, int $tooSmall, int $lowConfidence): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(ManualRegion::STATE_DONE))
			->set('found_count', $qb->createNamedParameter($found, IQueryBuilder::PARAM_INT))
			->set('too_small_count', $qb->createNamedParameter($tooSmall, IQueryBuilder::PARAM_INT))
			->set('low_confidence_count', $qb->createNamedParameter($lowConfidence, IQueryBuilder::PARAM_INT))
			->set('error', $qb->createNamedParameter(null))
			->set('processed_time', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($regionId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Deletes the regions of one image, whatever their state: when a new
	 * version of the photo replaces its faces, the regions and what their
	 * search found belong to the old one.
	 */
	public function removeFromImage(int $imageId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('image', $qb->createNamedParameter($imageId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Deletes the regions whose image is gone. An image goes in several ways,
	 * deleting the photo, the cleanup of stale images, a reset, removing a
	 * user, and they all remove its faces but know nothing of its regions;
	 * this catches all of them at once. The ids are read first and deleted in
	 * chunks, which works the same on every database.
	 *
	 * @return int how many regions were deleted
	 */
	public function deleteOrphaned(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id')
			->from($this->getTableName(), 'r')
			->leftJoin('r', 'facerecog_images', 'i', $qb->expr()->eq('r.image', 'i.id'))
			->where($qb->expr()->isNull('i.id'));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int) $row['id'];
		}
		$result->closeCursor();

		foreach (array_chunk($ids, 1000) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete($this->getTableName())
				->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}

		return count($ids);
	}

	/**
	 * Records that the region could not be searched, so that it is not taken
	 * again on every run, and says why.
	 */
	public function markFailed(int $regionId, string $error): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(ManualRegion::STATE_FAILED))
			->set('error', $qb->createNamedParameter(mb_substr($error, 0, 1024)))
			->set('processed_time', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($regionId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}

<?php
/**
 * @copyright Copyright (c) 2017-2020, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018-2019, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
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
use OCP\IUser;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ImageMapper extends QBMapper {
	/** @var FaceMapper Face mapper*/
	private $faceMapper;

	public function __construct(IDBConnection $db, FaceMapper $faceMapper) {
		parent::__construct($db, 'facerecog_images', '\OCA\FaceRecognition\Db\Image');
		$this->faceMapper = $faceMapper;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $imageId Id of Image to get
	 *
	 */
	public function find(string $userId, int $imageId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file', 'is_processed', 'error', 'last_processed_time', 'processing_duration')
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($imageId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Id of model to get
	 *
	 */
	public function findAll(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file', 'is_processed', 'error', 'last_processed_time', 'processing_duration')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)));
		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Id of model
	 * @param int $fileId Id of file to get Image
	 *
	 */
	public function findFromFile(string $userId, int $modelId, int $fileId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'is_processed', 'error')
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andwhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('file', $qb->createNamedParameter($fileId)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function imageExists(Image $image): ?int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select(['id'])
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('file', $qb->createParameter('file')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $image->getUser())
			->setParameter('file', $image->getFile())
			->setParameter('model', $image->getModel());
		$resultStatement = $query->execute();
		$row = $resultStatement->fetch();
		$resultStatement->closeCursor();
		return $row ? (int)$row['id'] : null;
	}

	public function countImages(int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('model', $model);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function countProcessedImages(int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			->setParameter('model', $model)
			->setParameter('is_processed', True);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function getPersonCount(string $userId, int $modelId, string $name): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('i.id') . ')'))
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('f.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)));

		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function avgProcessingDuration(int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('AVG(' . $qb->getColumnName('processing_duration') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			->setParameter('model', $model)
			->setParameter('is_processed', True);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function countUserImages(string $userId, int $model, bool $processed = false): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $userId)
			->setParameter('model', $model);

		if ($processed) {
			$query->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			      ->setParameter('is_processed', true);
		}

		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * @param IUser|null $user User for which to get images for. If not given, all images from instance are returned.
	 * @param int $modelId Model Id to get images for.
	 */
	public function findImagesWithoutFaces(IUser $user = null, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select(['id', 'user', 'file', 'model'])
			->from($this->getTableName())
			->where($qb->expr()->eq('is_processed',  $qb->createParameter('is_processed')))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->setParameter('is_processed', false, IQueryBuilder::PARAM_BOOL);
		if (!is_null($user)) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($user->getUID())));
		}
		return $this->findEntities($qb);
	}

	public function findImages(string $userId, int $model): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.file')
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)));

		$images = $this->findEntities($qb);
		return $images;
	}

	public function findFromPersonLike(string $userId, int $model, string $name, $offset = null, $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.file')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('f.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->eq('is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->like($qb->func()->lower('p.name'), $qb->createParameter('query')));

		$query = '%' . $this->db->escapeLikeParameter(strtolower($name)) . '%';
		$qb->setParameter('query', $query);

		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	public function findFromPerson(string $userId, int $model, string $name, $offset = null, $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.file')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('f.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->eq('is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)));

		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		return $this->findEntities($qb);
	}


	/**
	 * Writes to DB that image has been processed. Previously found faces are deleted and new ones are inserted.
	 * If there is exception, its stack trace is also updated.
	 *
	 * @param Image $image Image to be updated
	 * @param Face[] $faces Faces to insert
	 * @param int $duration Processing time, in milliseconds
	 * @param \Exception|null $e Any exception that happened during image processing
	 *
	 * @return void
	 */
	public function imageProcessed(Image $image, array $faces, int $duration, \Exception $e = null): void {
		$this->db->beginTransaction();
		try {
			// Update image itself
			//
			$error = null;
			if ($e !== null) {
				$error = substr($e->getMessage(), 0, 1024);
			}

			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set("is_processed", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set("error", $qb->createNamedParameter($error))
				->set("last_processed_time", $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
				->set("processing_duration", $qb->createNamedParameter($duration))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($image->id)))
				->execute();

			// Delete all previous faces
			//
			$qb = $this->db->getQueryBuilder();
			$qb->delete('facerecog_faces')
				->where($qb->expr()->eq('image', $qb->createNamedParameter($image->id)))
				->execute();

			// Insert all faces
			//
			foreach ($faces as $face) {
				$this->faceMapper->insertFace($face, $this->db);
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Resets image by deleting all associated faces and prepares it to be processed again
	 *
	 * @param Image $image Image to reset
	 *
	 * @return void
	 */
	public function resetImage(Image $image): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("error", $qb->createNamedParameter(null))
			->set("last_processed_time", $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($image->getUser())))
			->andWhere($qb->expr()->eq('file', $qb->createNamedParameter($image->getFile())))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($image->getModel())))
			->execute();
	}

	/**
	 * Resets all image with error from that user and prepares it to be processed again
	 *
	 * @param string $userId User to reset errors
	 *
	 * @return void
	 */
	public function resetErrors(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("error", $qb->createNamedParameter(null))
			->set("last_processed_time", $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('error'))
			->execute();
	}

	/**
	 * Deletes all images from that user.
	 *
	 * @param string $userId User to drop images from table.
	 *
	 * @return void
	 */
	public function deleteUserImages(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->execute();
	}

	/**
	 * Deletes all images from that user and Model
	 *
	 * @param string $userId User to drop images from table.
	 * @param int $modelId model to drop images from table.
	 *
	 * @return void
	 */
	public function deleteUserModel(string $userId, $modelId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->execute();
	}

}

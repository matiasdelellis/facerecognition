<?php
namespace OCA\FaceRecognition\Db\ImageMapperTraits\Queries;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Image;
use OCP\AppFramework\Db\DoesNotExistException;

trait UserImageQueriesTrait
{
	/**
	 * Find an image entity for a given user by image ID.
	 *
	 * @param string $userId Id of user
	 * @param int $imageId Id of Image to get
	 * @return Image|null The image entity if found, or null if not.
	 */
	public function find(string $userId, int $imageId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select(
				'i.id',
				'ui.user',
				'i.model',
				'i.nc_file_id AS file',
				'i.is_processed',
				'i.error',
				'i.last_processed_time',
				'i.processing_duration'
			)
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('ui.image_id', $qb->createNamedParameter($imageId)));

		try {
			$image = $this->findEntity($qb);

			$this->logDebug('Found image entity for user by image ID', [
				'uid'     => $userId,
				'imageId' => $imageId,
				'result'  => 'found',
			]);

			return $image;

		} catch (DoesNotExistException $e) {
			$this->logInfo('No image found for given user and image ID', [
				'uid'     => $userId,
				'imageId' => $imageId,
				'result'  => 'not_found',
			]);

			return null;

		} catch (\Throwable $e) {
			$this->logError('Unexpected error while finding image for user', [
				'uid'       => $userId,
				'imageId'   => $imageId,
				'exception' => $e,
			]);

			throw $e;
		}
	}

	/**
	 * Find an image entity by its ID.
	 *
	 * @param int $imageId Id of Image to get
	 * @return Image|null The image entity if found, or null if not.
	 */
	public function findFromImageId(int $imageId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select(
				'i.id',
				'i.model',
				'i.nc_file_id AS file',
				'i.is_processed',
				'i.error',
				'i.last_processed_time',
				'i.processing_duration'
			)
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('i.id', $qb->createNamedParameter($imageId)));

		try {
			$image = $this->findEntity($qb);

			$this->logDebug('Found image entity by ID', [
				'imageId' => $imageId,
				'result'  => 'found',
			]);

			return $image;

		} catch (DoesNotExistException $e) {
			$this->logInfo('No image found for given image ID', [
				'imageId' => $imageId,
				'result'  => 'not_found',
			]);

			return null;

		} catch (\Throwable $e) {
			$this->logError('Unexpected error while finding image by ID', [
				'imageId'   => $imageId,
				'exception' => $e,
			]);

			throw $e;
		}
	}

	/**
	 * Find all image entities for a given user and model.
	 *
	 * @param string $userId Id of user
	 * @param int $modelId Id of model to get
	 * @return Image[] Array of image entities
	 */
	public function findAll(string $userId, int $modelId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select(
					'i.id',
					'ui.user',
					'i.model',
					'i.nc_file_id AS file',
					'i.is_processed',
					'i.error',
					'i.last_processed_time',
					'i.processing_duration'
				)
				->from($this->getTableName(), 'i')
				->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
				->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)));

			$images = $this->findEntities($qb);
			$count = count($images);

			$this->logDebug('Fetched image entities for user and model', [
				'uid'     => $userId,
				'modelId' => $modelId,
				'count'   => $count,
                'sql' => $qb->getSQL(),
			]);

			return $images;

		} catch (\Throwable $e) {
			$this->logError('Failed to fetch image entities for user and model', [
				'uid'       => $userId,
				'modelId'   => $modelId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Find an image entity by user, model, and file IDs.
	 *
	 * @param string $userId Id of user
	 * @param int $modelId Id of model
	 * @param int $fileId Id of file to get Image
	 * @return Image|null The image entity if found, or null if not.
	 */
	public function findFromFile(string $userId, int $modelId, int $fileId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select(
					'i.id',
					'ui.user',
					'i.model',
					'i.nc_file_id AS file',
					'i.is_processed',
					'i.error',
					'i.last_processed_time',
					'i.processing_duration'
				)
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('i.nc_file_id', $qb->createNamedParameter($fileId)));

		try {
			$entity = $this->findEntity($qb);

			$this->logDebug('Found image entity from file lookup', [
				'uid'     => $userId,
				'modelId' => $modelId,
				'fileId'  => $fileId,
				'imageId' => $entity->getId(),
                'sql' => $qb->getSQL(),
			]);

			return $entity;

		} catch (DoesNotExistException $e) {
			$this->logInfo('No image found for given user, model, and file', [
				'uid'     => $userId,
				'modelId' => $modelId,
				'fileId'  => $fileId,
                'sql' => $qb->getSQL(),
			]);
			return null;

		} catch (\Throwable $e) {
			$this->logError('Unexpected error while finding image from file', [
				'uid'       => $userId,
				'modelId'   => $modelId,
				'fileId'    => $fileId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Check if any other user still has a connection to the given image ID.
	 *
	 * @param int $imageId Id of Image Entry
	 * @return bool True if another user has a connection, false otherwise.
	 */
	public function otherUserStilHasConnection(int $imageId): bool {
		try {
			$users = $this->findUsersForImageId($imageId);
			$hasConnection = count($users) > 1;

			$this->logDebug('Checked for other user connections to image', [
				'imageId'      => $imageId,
				'hasConnection'=> $hasConnection,
			]);

			return $hasConnection;

		} catch (\Throwable $e) {
			$this->logError('Failed to check other user connections to image', [
				'imageId'   => $imageId,
				'exception' => $e,
			]);
			throw $e;
		}
	}
	
	/**
	 * Check if an image entity already exists for a given user, file, and model.
	 *
	 * @param Image $image Image to check
	 * @return int|null Id of existing image, or null if not found
	 */
	public function imageExists(Image $image): ?int {
		try {
			$qb = $this->db->getQueryBuilder();
			$query = $qb
				->select('id')
				->from($this->getTableName(), 'i')
				->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
				->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
				->andWhere($qb->expr()->eq('i.nc_file_id', $qb->createParameter('file')))
				->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
				->setParameter('user', $image->getUser())
				->setParameter('file', $image->getFile())
				->setParameter('model', $image->getModel());

			$resultStatement = $query->executeQuery();
			$row = $resultStatement->fetch();
			$resultStatement->closeCursor();

			if ($row) {
				$this->logDebug('Checked for existing image', [
					'uid'     => $image->getUser(),
					'file'    => $image->getFile(),
					'model'   => $image->getModel(),
					'imageId' => (int)$row['id'],
               		'sql' => $qb->getSQL(),
				]);
				return (int)$row['id'];
			} else {
				$this->logInfo('Checked for existing image - not found', [
					'uid'    => $image->getUser(),
					'file'   => $image->getFile(),
					'model'  => $image->getModel(),
                	'sql' => $qb->getSQL(),
				]);
				return null;
			}

		} catch (\Throwable $e) {
			$this->logError('Failed to check if image exists', [
				'uid'       => $image->getUser(),
				'file'      => $image->getFile(),
				'model'     => $image->getModel(),
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Count the number of images for a given user and model.
	 *
	 * @param string $userId Id of user
	 * @param int $model Model Id to count images for
	 * @param bool $processed If true, count only processed images
	 * @return int Number of images
	 */
	public function countUserImages(string $userId, int $model, bool $processed = false): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$query = $qb
				->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
				->from($this->getTableName(), 'i')
				->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
				->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
				->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
				->setParameter('user', $userId)
				->setParameter('model', $model);

			if ($processed) {
				$query->andWhere($qb->expr()->eq('i.is_processed', $qb->createParameter('is_processed')))
					->setParameter('is_processed', true);
			}

			$resultStatement = $query->executeQuery();
			$data = $resultStatement->fetch(\PDO::FETCH_NUM);
			$resultStatement->closeCursor();

			$count = (int)$data[0];

			$this->logDebug('Counted images for user', [
				'uid'       => $userId,
				'modelId'   => $model,
				'processed' => $processed,
				'count'     => $count,
                'sql' => $qb->getSQL(),
			]);

			return $count;

		} catch (\Throwable $e) {
			$this->logError('Failed to count images for user', [
				'uid'       => $userId,
				'modelId'   => $model,
				'processed' => $processed,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Find images without faces for a given user and model.
	 *
	 * @param string|null $user User for which to get images. If null, all images from instance are returned.
	 * @param int $modelId Model Id to get images for
	 * @return array Array of Image entities
	 */
	public function findImagesWithoutFaces(?string $user, int $modelId): array {
		try {
			$qb = $this->getAllFileds();
			$qb->Where($qb->expr()->eq('i.is_processed', $qb->createParameter('is_processed')))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
				->groupBy('i.id')
				->setParameter('is_processed', false, IQueryBuilder::PARAM_BOOL);
			if ($user !== null) {
				$qb->andWhere($qb->expr()->eq('ui.user', $qb->createNamedParameter($user)));
			} 

			$images = $this->findEntities($qb);

			$this->logDebug('Found images without faces', [
				'user'       => $user ?? 'ALL USERS',
				'modelId'    => $modelId,
				'count'      => count($images),
                'sql' => $qb->getSQL(),
			]);

			return $images;

		} catch (\Throwable $e) {
			$this->logError('Failed to find images without faces', [
				'user'      => $user ?? 'ALL USERS',
				'modelId'   => $modelId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Find images for a given user and model.
	 *
	 * @param string $userId Id of user
	 * @param int $model Model Id to get images for
	 * @return array Array of Image entities
	 */
	public function findImages(string $userId, int $model): array {
		try {
			$qb = $this->getAllFileds();
			$qb	->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)));

			$images = $this->findEntities($qb);

			$this->logDebug('Found images for user', [
				'uid'     => $userId,
				'modelId' => $model,
				'count'   => count($images),
                'sql' => $qb->getSQL(),
			]);

			return $images;

		} catch (\Throwable $e) {
			$this->logError('Failed to find images for user', [
				'uid'       => $userId,
				'modelId'   => $model,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Find all user IDs linked to a given image ID.
	 *
	 * @param int $imageId Id of Image to get
	 * @return string[] Array of user IDs found.
	 */
	public function findUsersForImageId(int $imageId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$resultStatement = $qb->select('ui.user')
				->from('facerecog_user_images', 'ui')
				->where($qb->expr()->eq('ui.image_id', $qb->createNamedParameter($imageId)))
				->executeQuery();

			$data = $resultStatement->fetchAll(\PDO::FETCH_COLUMN);
			$resultStatement->closeCursor();

			$this->logDebug('Fetched users for image ID', [
				'imageId' => $imageId,
				'userCount' => count($data),
                'sql' => $qb->getSQL(),
			]);

			return $data ?: [];

		} catch (\Throwable $e) {
			$this->logError('Failed to fetch users for image ID', [
				'imageId' => $imageId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
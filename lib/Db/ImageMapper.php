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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use Psr\Log\LoggerInterface;

class ImageMapper extends QBMapper
{
	/** @var FaceMapper Face mapper*/
	private $faceMapper;
	/** @var LoggerInterface*/
	private $logger;

	public function __construct(IDBConnection $db, FaceMapper $faceMapper, LoggerInterface $logger)
	{
		parent::__construct($db, 'facerecog_images', '\OCA\FaceRecognition\Db\Image');
		$this->faceMapper = $faceMapper;
		$this->logger = $logger;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $imageId Id of Image to get
	 *
	 */
	public function find(string $userId, int $imageId): ?Image{
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'ui.user', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('ui.image_id', $qb->createNamedParameter($imageId)));
		try {
			$image = $this->findEntity($qb);
			$this->logger->debug('ImageMapper -- find -- Found image ID ' . $imageId . ' for user ' . $userId);
			return $image;
		} catch (DoesNotExistException $e) {
			$this->logger->info('ImageMapper -- find -- No image found for user ' . $userId . ', image ID ' . $imageId);
			return null;
		}
	}

	/**
	 * @param int $imageId Id of Image to get
	 *
	 */
	public function findFromImageId(int $imageId): ?Image{
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
			->from($this->getTableName(), 'i')
			->Where($qb->expr()->eq('i.id', $qb->createNamedParameter($imageId)));
		try {
			$image = $this->findEntity($qb);
			$this->logger->debug('ImageMapper -- findFromImageId -- Found image ID ' . $imageId);
			return $image;
		} catch (DoesNotExistException $e) {
			$this->logger->info('ImageMapper -- findFromImageId -- No image found for image ID ' . $imageId);
			return null;
		}
	}
	/**
	 * @param int $imageId Id of Image to get
	 *
	 */
	public function findUsersForImageId(int $imageId): ?array{
		$qb = $this->db->getQueryBuilder();
		$resultStatement = $qb->select('ui.user')
			->from('facerecog_user_images', 'ui')
			->Where($qb->expr()->eq('ui.image_id', $qb->createNamedParameter($imageId)))
			->executeQuery();

		$data = $resultStatement->fetchAll(\PDO::FETCH_COLUMN);
		$resultStatement->closeCursor();
		$this->logger->debug('ImageMapper -- findUsersForImageId -- Found ' . count($data) . ' users for image ID ' . $imageId);

		return $data;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Id of model to get
	 *
	 */
	public function findAll(string $userId, int $modelId): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'ui.user', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)));
		$images =$this->findEntities($qb);
		$this->logger->debug('ImageMapper -- findAll -- Found ' . count($images) . ' images for user ' . $userId . ', model ' . $modelId . 'RETURNED COUNT: ' . count($images));
		return $images;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Id of model
	 * @param int $fileId Id of file to get Image
	 *
	 */
	public function findFromFile(string $userId, int $modelId, int $fileId): ?Image{
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'ui.user', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andwhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('i.nc_file_id', $qb->createNamedParameter($fileId)));
		try {
			$entity = $this->findEntity($qb);
			$this->logger->debug('ImageMapper -- findFromFile -- Found image ID ' . $entity->getId() . ' for user ' . $userId . ', model ' . $modelId . ', file ' . $fileId);
			return $entity;
		} catch (DoesNotExistException $e) {
			$this->logger->info('ImageMapper -- findFromFile -- No image found for user ' . $userId . ', model ' . $modelId . ', file ' . $fileId);
			return null;
		}
	}

	/**
	 * @param int $imageId Id of Image Entry
	 */
	public function otherUserStilHasConnection(int $imageId): bool{
		$qb = $this->db->getQueryBuilder();
		$resultStatement = $qb
			->select($qb->func()->count('*'))
			->from('facerecog_user_images')
			->where($qb->expr()->eq('image_id', $qb->createNamedParameter($imageId)))
			->executeQuery();

		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		$this->logger->debug('ImageMapper -- otherUserStilHasConnection -- Checking if other users still have connection to image ID ' . $imageId . ' RETURNED: ' . ((int)$data[0] > 1?'TRUE':'FALSE'));

		return (int)$data[0] > 1;
	}

	#[\Override]
	public function insert(Entity $image): Entity{
		$qb = $this->db->getQueryBuilder();
		$queryExec = $qb
			->select(['id'])
			->from($this->getTableName(), 'i')
			->Where($qb->expr()->eq('i.nc_file_id', $qb->createParameter('file')))
			->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
			->setParameter('file', $image->getFile())
			->setParameter('model', $image->getModel())
			->executeQuery();
		$row = $queryExec->fetch();
		$queryExec->closeCursor();

		$imageID = $row ? (int)$row['id'] : null;
		if ($imageID === null) {
			$insertImage = $this->db->getQueryBuilder();

			$insertImage
				->insert($this->getTableName())
				->values([
					'nc_file_id' => $insertImage->createNamedParameter($image->getFile()),
					'model' => $insertImage->createNamedParameter($image->getModel()),
				])->executeStatement();
			$imageID = $insertImage->getLastInsertId();
		}
		$insertUserImages = $this->db->getQueryBuilder();
		$insertUserImages->insert('facerecog_user_images')
			->values([
				'user' => $insertUserImages->createNamedParameter($image->getUser()),
				'image_id' => $insertUserImages->createNamedParameter($imageID)
			])->executeStatement();

		$image->setId((int) $imageID);
		$this->logger->debug('ImageMapper -- insert -- Inserted image ID ' . $image->getId() . ' for user ' . $image->getUser());
		return $image;
	}

	#[\Override]
	public function update(Entity $entity): Entity{
		// if entity wasn't changed it makes no sense to run a db query
		$properties = $entity->getUpdatedFields();
		if (count($properties) === 0)
			return $entity;
		// entity needs an id
		$id = $entity->getId();
		if ($id === null) {
			throw new \InvalidArgumentException(
				'Entity which should be updated has no id'
			);
		}

		// get updated fields to save, fields have to be set using a setter to
		// be saved
		// do not update the id field
		// do not update the user field
		unset($properties['id']);
		unset($properties['user']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName);

		// build the fields
		foreach ($properties as $property => $updated) {
			$column = $entity->propertyToColumn($property);
			if ($column === "file") {
				$column = "nc_file_id";
			}
			$getter = 'get' . ucfirst($property);
			$value = $entity->$getter();

			$type = $this->getParameterTypeForProperty($entity, $property);
			$qb->set($column, $qb->createNamedParameter($value, $type));
		}

		$idType = $this->getParameterTypeForProperty($entity, 'id');

		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, $idType))
		);
		$qb->executeStatement();
		$this->logger->debug('ImageMapper -- update -- Updated image ID ' . $entity->getId() . ' for user ' . $entity->getUser());

		return $entity;
	}

	#[\Override]
	public function delete(Entity $entity): Entity{
		// First check if other users still have connection to this image
		if (!$this->otherUserStilHasConnection($entity->getId())) {
			// Delete image
			parent::delete($entity);
			$this->logger->debug('ImageMapper -- delete -- Deleted image ID ' . $entity->getId() . ' from database as no other user has connection to it');
		}
		else {
			// Delete only user-image connection
			$this->removeUserImageConnection($entity);
			$this->logger->info('ImageMapper -- delete -- Only connection removed from user: '. $entity->getuser() . ' Not deleting image ID ' . $entity->getId() . ' from database as other users still have connection to it');
		}
		return $entity;
	}

	/**
	 * @param Entity $entity image entity
	 * @param string $userName name of user
	 */
	public function removeUserImageConnection(Entity $entity){
		$qb = $this->db->getQueryBuilder();

		$qb->delete('facerecog_user_images')
			->where(
				$qb->expr()->eq('image_id', $qb->createNamedParameter($entity->getId()))
			)
			->andWhere(
				$qb->expr()->eq('user', $qb->createNamedParameter($entity->getUser()))
			);
		$qb->executeStatement();
		$this->logger->debug('ImageMapper -- removeUserImageConnection -- Removed image-user connection for user ' . $entity->getUser() . ' and image ID ' . $entity->getId());
	}
	/**
	 * @param Image $image Image to check
	 *
	 * @return int|null Id of existing image, or null if not found
	 */
	public function imageExists(Image $image): ?int{
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
			$this->logger->debug('ImageMapper -- imageExists -- Checking if image exists for user ' . $image->getUser() . ', file ' . $image->getFile() . ', model ' . $image->getModel() . 'RETURNED ID: ' . (int)$row['id']);
		}
		else
			$this->logger->info('ImageMapper -- imageExists -- Checking if image exists for user ' . $image->getUser() . ', file ' . $image->getFile() . ', model ' . $image->getModel() . 'RETURNED ID: null');
		return $row ? (int)$row['id'] : null;
	}

	/**
	 * @param int $model Model Id to count images for
	 *
	 */
	public function countImages(int $model): int{
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('model', $model);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		$this->logger->debug('ImageMapper -- countImages -- Counting images for model ' . $model . ' RETURNED COUNT: ' . (int)$data[0]);

		return (int)$data[0];
	}

	/**
	 * @param int $model Model Id to count images for
	 *
	 */
	public function countProcessedImages(int $model): int{
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			->setParameter('model', $model)
			->setParameter('is_processed', TRUE);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		$this->logger->debug('ImageMapper -- countProcessedImages -- Counting processed images for model ' . $model . ' RETURNED COUNT: ' . (int)$data[0]);

		return (int)$data[0];
	}

	/**
	 * @param int $model Model Id to get average processing duration for
	 *
	 */
	public function avgProcessingDuration(int $model): int{
		$sql = "SELECT AVG(`processing_duration`) FROM (select `processing_duration` FROM `*PREFIX*facerecog_images` WHERE (`model` = :model) AND (`is_processed` = :is_processed) ORDER BY `last_processed_time` DESC LIMIT 50) as t";
		$params = [
			'model' => $model,
			'is_processed' => true
		];
		$resultStatement = $this->db->executeQuery($sql, $params);
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();
		$this->logger->debug('ImageMapper -- avgProcessingDuration -- Getting average processing duration based on last 50 processed images for model ' . $model . ' RETURNED DURATION: ' . (int)$data[0]);

		return (int)$data[0];
	}

	/**
	 * @param string $userId Id of user
	 * @param int $model Model Id to count images for
	 * @param bool $processed If true, count only processed images
	 *
	 */
	public function countUserImages(string $userId, int $model, bool $processed = false): int{
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
		$this->logger->debug('ImageMapper -- countUserImages -- Counting images for user ' . $userId . ', model ' . $model . ', processed ' . ($processed ? 'true' : 'false') . ' RETURNED COUNT: ' . (int)$data[0]);

		return (int)$data[0];
	}

	/**
	 * @param IUser|null $user User for which to get images for. If not given, all images from instance are returned.
	 * @param int $modelId Model Id to get images for.
	 */
	public function findImagesWithoutFaces(?string $user, int $modelId): array{
		$qb = $this->db->getQueryBuilder();

		if (!is_null($user)) {
			$qb->select('i.id', 'ui.user', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
				->from($this->getTableName(), 'i')
				->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
				->Where($qb->expr()->eq('ui.user', $qb->createNamedParameter($user)))
				->andWhere($qb->expr()->eq('i.is_processed',  $qb->createParameter('is_processed')))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
				->setParameter('is_processed', false, IQueryBuilder::PARAM_BOOL);
		}
		else {
			$qb->select('i.id', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
				->from($this->getTableName(), 'i')
				->Where($qb->expr()->eq('i.is_processed',  $qb->createParameter('is_processed')))
				->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
				->setParameter('is_processed', false, IQueryBuilder::PARAM_BOOL);
		}
		$images = $this->findEntities($qb);
		$this->logger->debug('ImageMapper -- findImagesWithoutFaces -- Finding images without faces for user ' . ($user ?? 'ALL USERS') . ', model ' . $modelId . ' RETURNED COUNT: ' . count($images) . ' images');
		return $images;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $model Model Id to get images for
	 *
	 */
	public function findImages(string $userId, int $model): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'ui.user', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)));

		$images = $this->findEntities($qb);
		$this->logger->debug('ImageMapper -- findImages -- Finding images for user ' . $userId . ', model ' . $model . ' RETURNED COUNT: ' . count($images) . ' images');
		return $images;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Model Id to get images for
	 * @param string $name Name of person
	 * @param int|null $offset Offset for pagination
	 * @param int|null $limit Limit for pagination
	 *
	 */
	public function findFromPerson(string $userId, int $modelId, string $name, ?int $offset = null, ?int $limit = null): array{
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'ui.user', 'i.model', 'i.nc_file_id as file', 'i.is_processed', 'i.error', 'i.last_processed_time', 'i.processing_duration')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('i', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('i', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'cf.cluster_id'))
			->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)))
			->orderBy('i.nc_file_id', 'DESC');

		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		$images =$this->findEntities($qb);
		$this->logger->debug('ImageMapper -- findFromPerson -- user: ' . $userId . ', model: ' . $modelId . ', person: ' . $name . ', offset: ' . ($offset ?? 'NULL') . ', limit: ' . ($limit ?? 'NULL') . ' RETURNED COUNT: ' . count($images) . ' images');
		
		return $images;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Model Id to get images for
	 * @param string $name Name of person
	 *
	 */
	public function countFromPerson(string $userId, int $modelId, string $name): int{
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('*'))
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'))
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image_id', 'i.id'))
			->innerJoin('i', 'facerecog_cluster_faces', 'cf', $qb->expr()->eq('cf.face_id', 'f.id'))
			->innerJoin('i', 'facerecog_person_clusters', 'pc', $qb->expr()->eq('pc.cluster_id', 'cf.cluster_id'))
			->innerJoin('i', 'facerecog_persons', 'p', $qb->expr()->eq('pc.person_id', 'p.id'))
			->where($qb->expr()->eq('ui.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)));

		$result = $qb->executeQuery();
		$column = (int)$result->fetchOne();
		$result->closeCursor();
		$this->logger->debug('ImageMapper -- countFromPerson -- user: ' . $userId . ', model: ' . $modelId . ', person: ' . $name . ' RETURNED COUNT: ' . $column);

		return $column;
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
	public function imageProcessed(int $imageId, array $faces, int $duration, ?\Exception $e = null): void{
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
				->set("last_processed_time", $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE_MUTABLE))
				->set("processing_duration", $qb->createNamedParameter($duration))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($imageId)))
				->executeStatement();

			$this->logger->debug('ImageMapper -- imageProcessed -- Image ' . $imageId . ' processed with ' . count($faces) . ' faces, duration ' . $duration . ' ms' . ($error ? ', error: ' . $error : ''));
			// Delete all previous faces
			//
			$this->faceMapper->removeFromImage($imageId, $this->db);

			// Insert all faces
			//
			foreach ($faces as $face) {
				$this->faceMapper->insertFace($face, $this->db);
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			$this->logger->error('ImageMapper -- imageProcessed -- ERROR processing image ' . $imageId . ': ' . $e->getMessage());
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
	public function resetImage(Image $image): void{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("error", $qb->createNamedParameter(null))
			->set("last_processed_time", $qb->createNamedParameter(null))
			->Where($qb->expr()->eq('nc_file_id', $qb->createNamedParameter($image->getFile())))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($image->getModel())))
			->executeStatement();
		$this->faceMapper->removeFromImage($image->getId(), $this->db);
		$this->logger->debug('ImageMapper -- resetImage -- Image ' . $image->getId() . ' reset for processing again');
	}

	/**
	 * Resets all image with error from that user and prepares it to be processed again
	 *
	 * @param string $userId User to reset errors
	 *
	 * @return void
	 */
	public function resetErrors(string $userId): void{
		//Collect all imageId whitch has error and belongs to that user
		$sub = $this->db->getQueryBuilder();
		$sub->select('ui.image_id')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
			->where($sub->expr()->eq('ui.user', $sub->createParameter('userId')))
			->andWhere($sub->expr()->isNotNull('i.error'));
		$sql = $sub->getSQL();

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("error", $qb->createParameter('error'))
			->set("last_processed_time", $qb->createParameter("last_processed_time"))
			->Where('id in (' . $sql . ')')
			->setParameter('userId', $userId, IQueryBuilder::PARAM_STR)
			->setParameter('error', null)
			->setParameter('last_processed_time', null)
			->executeStatement();
		$this->logger->debug('ImageMapper -- resetErrors -- Resetting images with errors for user ' . $userId);
	}

	/**
	 * Deletes all images from that user.
	 *
	 * @param string $userId User to drop images from table.
	 *
	 * @return void
	 */
	public function deleteUserImages(string $userId): void{
		//Delete User-ImageConnection
		$qb = $this->db->getQueryBuilder();
		$qb->delete('facerecog_user_images')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->executeStatement();

		//Collect all imageId whitch has no more references by other Users
		$sub = $this->db->getQueryBuilder();
		$sub->select('i.id')
			->from($this->getTableName(), 'i')
			->leftJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
			->where($sub->expr()->isNull('ui.image_id'))
			->groupBy('i.id');

		//Delete image where the connection table has no reference
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->Where('id in (' . $sub->getSQL() . ')')
			->executeStatement();
		$this->logger->debug('ImageMapper -- deleteUserImages -- Deleted images for user ' . $userId);
	}

	/**
	 * Deletes all images from that user and Model
	 *
	 * @param string $userId User to drop images from table.
	 * @param int $modelId model to drop images from table.
	 *
	 * @return void
	 */
	public function deleteUserModel(string $userId, int $modelId): void{
		//Collect all imageId where user has connection and it's the required model
		$sub = $this->db->getQueryBuilder();
		$sub->select('i.id')
			->from($this->getTableName(), 'i')
			->leftJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
			->where($sub->expr()->eq('ui.user', $sub->createParameter('userId')))
			->andWhere($sub->expr()->eq('i.model', $sub->createParameter('modelId')))
			->groupBy('i.id');
		$sql = $sub->getSQL();
		//Delete User-ImageConnection
		$qb = $this->db->getQueryBuilder();
		$qb->delete('facerecog_user_images')
			->where($qb->expr()->eq('user', $qb->createParameter('userId')))
			->AndWhere('image_id in (' . $sql . ')')
			->setParameter('userId', $userId, IQueryBuilder::PARAM_STR)
			->setParameter('modelId', $modelId, IQueryBuilder::PARAM_INT)
			->executeStatement();

		//Collect all imageId whitch has no more references by other Users
		$sub = $this->db->getQueryBuilder();
		$sub->select('i.id')
			->from($this->getTableName(), 'i')
			->leftJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
			->where($sub->expr()->isNull('ui.image_id'))
			->groupBy('i.id');
		$sql = $sub->getSQL();
		//Delete image where the connection table has no reference
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->Where('id in (' . $sql . ')')
			->executeStatement();
		$this->logger->debug('ImageMapper -- deleteUserModel -- Deleted images for user ' . $userId . ' and model ' . $modelId);
	}
}

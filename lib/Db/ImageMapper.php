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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\Entity;
use OCA\FaceRecognition\Db\ImageMapperTraits\Processing\ImageStatsTrait;
use OCA\FaceRecognition\Db\ImageMapperTraits\Processing\ImageProcessTrait;
use OCA\FaceRecognition\Db\ImageMapperTraits\Processing\ImageResetTrait;
use OCA\FaceRecognition\Db\ImageMapperTraits\Queries\UserImageQueriesTrait;
use OCA\FaceRecognition\Db\ImageMapperTraits\Queries\PersonImageQueriesTrait;
use OCA\FaceRecognition\Db\ImageMapperTraits\Deletion\UserImageDeletionTrait;
use OCA\FaceRecognition\Traits\LoggerTrait;
use Psr\Log\LoggerInterface;

class ImageMapper extends QBMapper
{
	use LoggerTrait;
    use ImageStatsTrait;
    use ImageProcessTrait;
    use ImageResetTrait;
    use UserImageQueriesTrait;
    use PersonImageQueriesTrait;
    use UserImageDeletionTrait;

	/** @var FaceMapper Face mapper*/
	private $faceMapper;

	public function __construct(IDBConnection $db, FaceMapper $faceMapper, LoggerInterface $logger)
	{
		parent::__construct($db, 'facerecog_images', '\OCA\FaceRecognition\Db\Image');
		$this->faceMapper = $faceMapper;
		$this->setLogger($logger);
	}
    

	/**
	 * @param Entity $image image entity
	 */
	#[\Override]
	public function insert(Entity $image): Entity {
		try {
			$qb = $this->db->getQueryBuilder();
			$queryExec = $qb
				->select(['id'])
				->from($this->getTableName(), 'i')
				->where($qb->expr()->eq('i.nc_file_id', $qb->createParameter('file')))
				->andWhere($qb->expr()->eq('i.model', $qb->createParameter('model')))
				->setParameter('file', $image->getFile())
				->setParameter('model', $image->getModel())
				->executeQuery();

			$row = $queryExec->fetch();
			$queryExec->closeCursor();

			$imageID = $row ? (int)$row['id'] : null;

			if ($imageID === null) {
				$qb = $this->db->getQueryBuilder();
				$qb
					->insert($this->getTableName())
					->values([
						'nc_file_id' => $qb->createNamedParameter($image->getFile()),
						'model'      => $qb->createNamedParameter($image->getModel()),
					])
					->executeStatement();

				$imageID = $qb->getLastInsertId();
				$this->logInfo('New image inserted', [
					'imageId' => $imageID,
					'file'    => $image->getFile(),
					'model'   => $image->getModel(),
					'sql'     => $qb->getSQL(),
				]);
			}
			else {
				$this->logDebug('Image already exists, reusing existing image', [
					'imageId' => $imageID,
					'file'    => $image->getFile(),
					'model'   => $image->getModel(),
					'sql'     => $qb->getSQL(),
				]);
			}

			$qb = $this->db->getQueryBuilder();
			$queryExec = $qb
				->select($qb->expr()->literal('1'))
				->from('facerecog_user_images', 'ui')
				->where($qb->expr()->eq('ui.user', $qb->createParameter('user')))
				->andWhere($qb->expr()->eq('ui.image_id', $qb->createParameter('image_id')))
				->setParameter('user', $image->getUser())
				->setParameter('image_id', $imageID)
				->executeQuery();
			$exists = $queryExec->fetch();
			$queryExec->closeCursor();	
			if ($exists) {
				$this->logDebug('Image-user connection already exists, skipping insert', [
					'imageId' => $imageID,
					'uid'     => $image->getUser(),
				]);
			}
			else {
				$qb = $this->db->getQueryBuilder();
				$qb
					->insert('facerecog_user_images')
					->values([
						'user'     => $qb->createNamedParameter($image->getUser()),
						'image_id' => $qb->createNamedParameter($imageID),
					])
					->executeStatement();

				$this->logInfo('New image-user connection inserted', [
					'imageId' => $imageID,
					'uid'     => $image->getUser(),
					'sql'     => $qb->getSQL(),
				]);
			}
			$image->setId((int)$imageID);

			$this->logInfo('Inserted image entity finished', [
				'imageId' => $image->getId(),
				'uid'     => $image->getUser(),
				'file'    => $image->getFile(),
				'model'   => $image->getModel(),
			]);

			return $image;

		} catch (\Throwable $e) {
			$this->logError('Failed to insert image entity', [
				'imageId'   => $image->getId(),
				'uid'       => $image->getUser(),
				'file'      => $image->getFile(),
				'model'     => $image->getModel(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * @param Entity $entity image entity
	 */
	#[\Override]
	public function update(Entity $entity): Entity {
		// if entity wasn't changed, no need to run a DB query
		$properties = $entity->getUpdatedFields();
		if (count($properties) === 0) {
			return $entity;
		}

		// entity needs an id
		$id = $entity->getId();
		if ($id === null) {
			throw new \InvalidArgumentException('Entity which should be updated has no id');
		}

		// remove fields that should not be updated
		unset($properties['id'], $properties['user']);

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->tableName);

			// build the set clause
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
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, $idType)))
			->executeStatement();

			$this->logInfo('Updated image entity', [
				'imageId' => $entity->getId(),
				'uid'     => $entity->getUser(),
				'updated' => array_keys($properties),
			]);

			return $entity;

		} catch (\Throwable $e) {
			$this->logError('Failed to update image entity', [
				'imageId'   => $entity->getId(),
				'uid'       => $entity->getUser(),
				'updated'   => array_keys($properties),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	#[\Override]
	public function delete(Entity $entity): Entity {
		try {
			// Delete image
			parent::delete($entity);

			$this->logInfo('Deleted image entity from database', [
				'imageId' => $entity->getId(),
				'uid'     => $entity->getUser(),
				'note'    => 'Deleted image with all connections',
			]);

			return $entity;

		} catch (\Throwable $e) {
			$this->logError('Failed to delete image entity', [
				'imageId'   => $entity->getId(),
				'uid'       => $entity->getUser(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Builds a query builder selecting all fields of the image along with the user.
	 *
	 * @return IQueryBuilder The query builder instance
	 */
	private function getAllFileds() : IQueryBuilder {
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
			->innerJoin('i', 'facerecog_user_images', 'ui', $qb->expr()->eq('ui.image_id', 'i.id'));
		return $qb;
	}
}
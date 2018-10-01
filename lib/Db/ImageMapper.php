<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
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

use OCP\AppFramework\Db\Mapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ImageMapper extends Mapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition_images', '\OCA\FaceRecognition\Db\Image');
	}

	public function imageExists(Image $image) {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select(['id'])
			->from('face_recognition_images')
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

	public function countUserImages(string $userId, $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('face_recognition_images')
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $userId)
			->setParameter('model', $model);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function countUserProcessedImages(string $userId, $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('face_recognition_images')
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('is_processed', True);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * @param IUser|null $user User for which to get images for. If not given, all images from instance are returned.
	 */
	public function findImagesWithoutFaces(IUser $user = null) {
		$qb = $this->db->getQueryBuilder();
		$params = array();

		$query = $qb
			->select(['id', 'user', 'file', 'model'])
			->from('face_recognition_images')
			->where($qb->expr()->eq('is_processed',  $qb->createParameter('is_processed')));
			$params['is_processed'] = False;
		if (!is_null($user)) {
			$query->andWhere($qb->expr()->eq('user', $qb->createParameter('user')));
			$params['user'] = $user->getUID();
		}

		$images = $this->findEntities($qb->getSQL(), $params);
		return $images;
	}

	public function imageProcessed(Image $image, array $faces, int $duration) {
		$this->db->beginTransaction();
		$currentDateTime = new \DateTime();
		try {
			// Update image itself
			//
			$qb = $this->db->getQueryBuilder();
			$qb->update('face_recognition_images')
				->set("is_processed", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set("error", $qb->createNamedParameter(null))
				->set("last_processed_time", $qb->createNamedParameter($currentDateTime, IQueryBuilder::PARAM_DATE))
				->set("processing_duration", $qb->createNamedParameter($duration))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($image->id)))
				->execute();

			// Delete all previous faces
			//
			$qb = $this->db->getQueryBuilder();
			$qb->delete('face_recognition_faces')
				->where($qb->expr()->eq('image', $qb->createNamedParameter($image->id)))
				->execute();

			// Insert all faces
			//
			foreach ($faces as $face) {
				$json_descriptor = json_encode($face["descriptor"]);
				// Simple INSERT will close cursor and we want to be in transaction, so use hard way
				// todo: should we move this to FaceNewMapper (don't forget to hand over connection though)
				$qb = $this->db->getQueryBuilder();
				$qb->insert('face_recognition_faces')
					->values([
						'image' => $qb->createNamedParameter($image->id),
						'person' => $qb->createNamedParameter(null),
						'left' => $qb->createNamedParameter($face["left"]),
						'right' => $qb->createNamedParameter($face["right"]),
						'top' => $qb->createNamedParameter($face["top"]),
						'bottom' => $qb->createNamedParameter($face["bottom"]),
						'descriptor' => $qb->createNamedParameter($json_descriptor),
						'creation_time' => $qb->createNamedParameter($currentDateTime, IQueryBuilder::PARAM_DATE),
					])
					->execute();
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
	 */
	public function resetImage(Image $image) {
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
}

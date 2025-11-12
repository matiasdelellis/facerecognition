<?php
namespace OCA\FaceRecognition\Db\ImageMapperTraits\Deletion;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\Entity;

trait UserImageDeletionTrait
{
	/**
	 * Remove the connection between an image entity and a user.
	 *
	 * @param Entity $entity image entity
	 */
	public function removeUserImageConnection(Entity $entity): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('facerecog_user_images')
				->where($qb->expr()->eq('image_id', $qb->createNamedParameter($entity->getId())))
				->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($entity->getUser())))
				->executeStatement();

			$this->logDebug('Removed image-user connection', [
				'imageId' => $entity->getId(),
				'uid'     => $entity->getUser(),
                'sql' => $qb->getSQL(),
			]);

		} catch (\Throwable $e) {
			$this->logError('Failed to remove image-user connection', [
				'imageId'   => $entity->getId(),
				'uid'       => $entity->getUser(),
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Deletes all images associated with a user.
	 *
	 * @param string $userId User ID to delete images for
	 */
	public function deleteUserImages(string $userId): void {
		try {
			// Delete all user-image connections for this user
			$qb = $this->db->getQueryBuilder();
			$qb->delete('facerecog_user_images')
				->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
				->executeStatement();

			// Collect all images with no more references from other users
			$sub = $this->db->getQueryBuilder();
			$subQuery = $sub->select('i.id')
				->from($this->getTableName(), 'i')
				->leftJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
				->where($sub->expr()->isNull('ui.image_id'))
				->groupBy('i.id')
				->executeQuery();

			$imagesToDelete = $subQuery->fetchAll();
			$subQuery->closeCursor();

			// Delete images with no references
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('id', $qb->createParameter('image_id')));

			foreach ($imagesToDelete as $image) {
				$qb->setParameter('image_id', $image['id'], IQueryBuilder::PARAM_INT)
					->executeStatement();
			}

			$this->logInfo('Deleted user images', [
				'uid'         => $userId,
				'deletedCount'=> count($imagesToDelete),
                'sql' => $qb->getSQL(),
			]);

		} catch (\Throwable $e) {
			$this->logError('Failed to delete user images', [
				'uid'       => $userId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
	
	/**
	 * Deletes all images from a specific user and model.
	 *
	 * @param string $userId  User ID to drop images for
	 * @param int    $modelId Model ID to drop images for
	 */
	public function deleteUserModel(string $userId, int $modelId): void {
		try {
			// Collect all image IDs where user has connection and it's the specified model
			$sub = $this->db->getQueryBuilder();
			$subQuery = $sub->select('i.id')
				->from($this->getTableName(), 'i')
				->leftJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
				->where($sub->expr()->eq('ui.user', $sub->createParameter('userId')))
				->andWhere($sub->expr()->eq('i.model', $sub->createParameter('modelId')))
				->groupBy('i.id')
				->setParameter('userId', $userId, IQueryBuilder::PARAM_STR)
				->setParameter('modelId', $modelId, IQueryBuilder::PARAM_INT)
				->executeQuery();

			$imageUserConnectionsToDelete = $subQuery->fetchAll();
			$subQuery->closeCursor();

			// Delete User-Image connections
			$qb = $this->db->getQueryBuilder();
			$qb->delete('facerecog_user_images')
				->where($qb->expr()->eq('user', $qb->createParameter('userId')))
				->andWhere($qb->expr()->eq('image_id', $qb->createParameter('image_id')))
				->setParameter('userId', $userId, IQueryBuilder::PARAM_STR);

			foreach ($imageUserConnectionsToDelete as $image) {
				$qb->setParameter('image_id', $image['id'], IQueryBuilder::PARAM_INT)
					->executeStatement();
			}

			$this->logInfo('Deleted user-image connections for model', [
				'uid'          => $userId,
				'modelId'      => $modelId,
				'deletedCount' => count($imageUserConnectionsToDelete),
                'sql' => $qb->getSQL(),
			]);

			// Collect images with no more references from other users
			$sub = $this->db->getQueryBuilder();
			$subQuery = $sub->select('id')
				->from($this->getTableName(), 'i')
				->leftJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
				->where($sub->expr()->isNull('ui.image_id'))
				->groupBy('i.id')
				->executeQuery();

			$imagesToDelete = $subQuery->fetchAll();
			$subQuery->closeCursor();

			// Delete images with no references
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('id', $qb->createParameter('image_id')));

			foreach ($imagesToDelete as $image) {
				$qb->setParameter('image_id', $image['id'], IQueryBuilder::PARAM_INT)
					->executeStatement();
			}

			$this->logInfo('Deleted images with no references for user and model', [
				'uid'          => $userId,
				'modelId'      => $modelId,
				'deletedCount' => count($imagesToDelete),
                'sql' => $qb->getSQL(),
			]);

		} catch (\Throwable $e) {
			$this->logError('Failed to delete user model images', [
				'uid'       => $userId,
				'modelId'   => $modelId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
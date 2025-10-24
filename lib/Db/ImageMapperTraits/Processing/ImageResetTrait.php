<?php
namespace OCA\FaceRecognition\Db\ImageMapperTraits\Processing;

use OCA\FaceRecognition\Db\Image;
use OCP\DB\QueryBuilder\IQueryBuilder;

trait ImageResetTrait
{
	/**
	 * Resets an image by deleting all associated faces and preparing it to be processed again.
	 *
	 * @param Image $image Image to reset
	 */
	public function resetImage(Image $image): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set("error", $qb->createNamedParameter(null))
				->set("last_processed_time", $qb->createNamedParameter(null))
				->where($qb->expr()->eq('nc_file_id', $qb->createNamedParameter($image->getFile())))
				->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($image->getModel())))
				->executeStatement();

			// Remove all associated faces
			$this->faceMapper->removeFromImage($image->getId(), $this->db);

			$this->logInfo('Image reset for processing', [
				'imageId' => $image->getId(),
				'file'    => $image->getFile(),
				'modelId' => $image->getModel(),
                'sql' => $qb->getSQL(),
			]);

		} catch (\Throwable $e) {
			$this->logError('Failed to reset image', [
				'imageId'   => $image->getId(),
				'file'      => $image->getFile(),
				'modelId'   => $image->getModel(),
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Resets all images with errors for a given user and prepares them to be processed again.
	 *
	 * @param string $userId User ID to reset errors for
	 */
	public function resetErrors(string $userId): void {
		try {
			// Collect all image IDs that have errors and belong to the user
			$sub = $this->db->getQueryBuilder();
			$subQuery = $sub->select('ui.image_id AS id')
				->from($this->getTableName(), 'i')
				->innerJoin('i', 'facerecog_user_images', 'ui', $sub->expr()->eq('ui.image_id', 'i.id'))
				->where($sub->expr()->eq('ui.user', $sub->createParameter('userId')))
				->andWhere($sub->expr()->isNotNull('i.error'))
				->setParameter('userId', $userId, IQueryBuilder::PARAM_STR)
				->executeQuery();

			$imagesToReset = $subQuery->fetchAll();
			$subQuery->closeCursor();

			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set("error", $qb->createParameter('error'))
				->set("last_processed_time", $qb->createParameter('last_processed_time'))
				->where($qb->expr()->eq('id', $qb->createParameter('image_id')))
				->setParameter('error', null)
				->setParameter('last_processed_time', null);

			foreach ($imagesToReset as $image) {
				$qb->setParameter('image_id', $image['id'], IQueryBuilder::PARAM_INT)
					->executeStatement();
			}

			$this->logInfo('Reset images with errors for user', [
				'uid'        => $userId,
				'resetCount' => count($imagesToReset),
                'sql' => $qb->getSQL(),
			]);

		} catch (\Throwable $e) {
			$this->logError('Failed to reset images with errors for user', [
				'uid'       => $userId,
                'sql' => $qb->getSQL(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
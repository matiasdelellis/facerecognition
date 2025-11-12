<?php
namespace OCA\FaceRecognition\Db\ImageMapperTraits\Processing;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\FaceRecognition\Db\Face;

trait ImageProcessTrait
{
	/**
	* Marks an image as processed, replaces its faces, and logs any exception.
	*
	* @param int $imageId ID of the image to update
	* @param Face[] $faces Faces to insert
	* @param int $duration Processing time in milliseconds
	* @param \Exception|null $e Any exception that occurred during image processing
	*/
	public function imageProcessed(int $imageId, array $faces, int $duration, ?\Exception $e = null): void {
		$this->db->beginTransaction();
		try {
			// Prepare error message if exception occurred
			$error = $e !== null ? substr($e->getMessage(), 0, 1024) : null;

			// Update image record
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set("is_processed", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set("error", $qb->createNamedParameter($error))
				->set("last_processed_time", $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE_MUTABLE))
				->set("processing_duration", $qb->createNamedParameter($duration))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($imageId)))
				->executeStatement();

			$this->logInfo('Image processed', [
				'imageId'       => $imageId,
				'facesCount'    => count($faces),
				'durationMs'    => $duration,
				'error'         => $error,
                'sql' => $qb->getSQL(),
			]);

			// Delete previous faces
			$this->faceMapper->removeFromImage($imageId, $this->db);

			// Insert new faces
			foreach ($faces as $face) {
				$this->faceMapper->insertFace($face, $this->db);
			}

			$this->db->commit();

		} catch (\Throwable $ex) {
			$this->db->rollBack();
			$this->logError('Error processing image', [
				'imageId'   => $imageId,
				'facesCount'=> count($faces),
				'durationMs'=> $duration,
                'sql' => $qb->getSQL(),
				'exception' => $ex,
			]);
			throw $ex;
		}
	}
}
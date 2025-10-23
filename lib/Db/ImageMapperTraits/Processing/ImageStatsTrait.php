<?php
namespace OCA\FaceRecognition\Db\ImageMapperTraits\Processing;

trait ImageStatsTrait
{
	/**
	 * Count the number of images for a given model.
	 *
	 * @param int $model Model Id to count images for
	 * @return int Number of images
	 */
	public function countImages(int $model): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$query = $qb
				->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
				->from($this->getTableName())
				->where($qb->expr()->eq('model', $qb->createParameter('model')))
				->setParameter('model', $model);

			$resultStatement = $query->executeQuery();
			$data = $resultStatement->fetch(\PDO::FETCH_NUM);
			$resultStatement->closeCursor();

			$count = (int)$data[0];

			$this->logDebug('Counted images for model', [
				'modelId' => $model,
				'count'   => $count,
			]);

			return $count;

		} catch (\Throwable $e) {
			$this->logError('Failed to count images for model', [
				'modelId'   => $model,
				'exception' => $e,
			]);
			throw $e;
		}
	}

	/**
	 * Count the number of processed images for a given model.
	 *
	 * @param int $model Model Id to count images for
	 * @return int Number of processed images
	 */
	public function countProcessedImages(int $model): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$query = $qb
				->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
				->from($this->getTableName())
				->where($qb->expr()->eq('model', $qb->createParameter('model')))
				->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
				->setParameter('model', $model)
				->setParameter('is_processed', true);

			$resultStatement = $query->executeQuery();
			$data = $resultStatement->fetch(\PDO::FETCH_NUM);
			$resultStatement->closeCursor();

			$count = (int)$data[0];

			$this->logDebug('Counted processed images for model', [
				'modelId' => $model,
				'count'   => $count,
			]);

			return $count;

		} catch (\Throwable $e) {
			$this->logError('Failed to count processed images for model', [
				'modelId'   => $model,
				'exception' => $e,
			]);
			throw $e;
		}
	}
	
	/**
	 * Get the average processing duration of the last 50 processed images for a given model.
	 *
	 * @param int $model Model Id to get average processing duration for
	 * @return int Average processing duration in seconds
	 */
	public function avgProcessingDuration(int $model): int {
		try {
			$sql = "SELECT AVG(`processing_duration`) 
					FROM (
						SELECT `processing_duration` 
						FROM `*PREFIX*facerecog_images` 
						WHERE (`model` = :model) AND (`is_processed` = :is_processed) 
						ORDER BY `last_processed_time` DESC 
						LIMIT 50
					) AS t";

			$params = [
				'model'        => $model,
				'is_processed' => true,
			];

			$resultStatement = $this->db->executeQuery($sql, $params);
			$data = $resultStatement->fetch(\PDO::FETCH_NUM);
			$resultStatement->closeCursor();

			$avgDuration = (int)$data[0];

			$this->logInfo('Calculated average processing duration for model', [
				'modelId'      => $model,
				'avgDuration'  => $avgDuration,
				'rowsConsidered' => 50,
			]);

			return $avgDuration;

		} catch (\Throwable $e) {
			$this->logError('Failed to calculate average processing duration for model', [
				'modelId'   => $model,
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
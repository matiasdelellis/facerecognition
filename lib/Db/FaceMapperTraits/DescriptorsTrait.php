<?php
namespace OCA\FaceRecognition\Db\FaceMapperTraits;

use OCP\DB\QueryBuilder\IQueryBuilder;

trait DescriptorsTrait {
	public function findDescriptorsBathed(array $faceIds): array {
		$descriptors = [];

		for ($i = 0; $i < count($faceIds); $i += 1000) {
			$sliced = array_slice($faceIds, $i, 1000, true);

			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'descriptor')
				->from($this->getTableName(), 'f')
				->where($qb->expr()->in('id', $qb->createParameter('face_ids')));
			$qb->setParameter('face_ids', $sliced, IQueryBuilder::PARAM_INT_ARRAY);

			try {
				$faces = $this->findEntities($qb);

				foreach ($faces as $face) {
					$descriptors[] = [
						'id' => $face->getId(),
						'descriptor' => json_decode($face->getDescriptor()),
					];
				}

				// Structured debug logging (auto adds context)
				$this->logDebug('Processed descriptor batch', [
					'batchNumber' => ($i / 1000) + 1,
					'facesInBatch' => count($faces),
					'totalProcessed' => count($descriptors),
				]);
			} catch (\Throwable $e) {
				$this->logError('Error processing descriptor batch', [
					'batchIndex' => $i,
					'error' => $e->getMessage(),
				]);
				// Depending on policy: continue or rethrow
				continue;
			}
		}

		$this->logDebug('Completed descriptor retrieval', [
			'totalDescriptors' => count($descriptors),
			'totalRequested' => count($faceIds),
		]);

		return $descriptors;
	}
}
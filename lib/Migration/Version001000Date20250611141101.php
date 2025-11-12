<?php

declare(strict_types=1);

namespace OCA\FaceRecognition\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

use OCP\IDBConnection;

/**
 * MIIGRATE TO V0.9.8
 * Step1: Create new tables and duplicate data into those
 * - Step2: Remove duplications from images, and faces -> Remove original columns
 */
class Version001000Date20250611141101 extends SimpleMigrationStep {

	private $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->warning("Starting deduplication of images and faces");
		$output->warning("This might take a while depending on how many images you have");
		$output->warning("Please be patient and do not stop the process");
		$deduplicatedFiles = 0;
		//Get images which are duplicated
		$resultDuplicatedImages = $this->getDuplicatedFiles();
		while ($row = $resultDuplicatedImages->fetch()) {
			$ncFileId = $row['nc_file_id'];
			$modelNumber = $row['model'];
			$flaggedForKEEP = -1;
			//Get Images by nextcloud FileID and model <- these are shared files
			$resultImageEntries = $this->getImagesByModelAndNcFileId($ncFileId, $modelNumber);
			while ($duplicatedRow = $resultImageEntries->fetch()) {
				if	($flaggedForKEEP < 0)
				{
					//Keep the first of all duplicates
					$flaggedForKEEP = $duplicatedRow['id'];
				}
				else
				{
					//Handle other duplicates
					$currentImage = $duplicatedRow['id'];
					$this->updateUsersImages($currentImage, $flaggedForKEEP);
					$this->handleFaces($currentImage, $flaggedForKEEP);
					$this->removeImage($currentImage);
				}
			}
			$resultImageEntries->closeCursor();
			$deduplicatedFiles++;
			$output->debug("Deduplicated file ID: ".$ncFileId." and model: ".$modelNumber);
		}
		$resultDuplicatedImages->closeCursor();
		$output->warning("Deduplication done. Total deduplicated files: ".$deduplicatedFiles);
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$output->warning("Removing old columns from images, faces and persons tables");
		$schema = $schemaClosure();

		if ($schema->hasTable('facerecog_images')) {
			$table = $schema->getTable('facerecog_images');
			if ($table->hasColumn('user'))
				$table->dropColumn('user');
        }
		if ($schema->hasTable('facerecog_faces')) {
			$table = $schema->getTable('facerecog_faces');
			if ($table->hasColumn('person'))
				$table->dropColumn('person');
				$table->dropColumn('is_groupable');
        }
		
		if ($schema->hasTable('facerecog_clusters')) {
			$table = $schema->getTable('facerecog_clusters');
			if ($table->hasColumn('name'))
				$table->dropColumn('name');
        }

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	/**
	 * Update old image ID to the one we keep
	 * @param $imageId image ID what is removed
	 * @param $flaggedForKEEP image ID what is replaced to
	 */
	protected function updateUsersImages($imageId, $flaggedForKEEP): void {
		$builder =$this->connection->getQueryBuilder();
		$builder
			->update('facerecog_user_images')
			->set('image_id', $builder->createNamedParameter($flaggedForKEEP))
			->Where($builder->expr()->eq('image_id', $builder->createNamedParameter($imageId)))
			->executeStatement();
	}

	/**
	 * Update faces of the image to be connected to the image what we keep after the deduplication
	 * Or if faces are duplicate remove the one which is connected to the deletable picture
	 * @param $imageId the old image ID
	 * @param $flaggedForKEEP new image ID what is replaced to
	 */
	protected function handleFaces($imageId, $flaggedForKEEP): void {
		$resultOldFaceEntries=$this->getFacesFromImage($imageId);
		$resultNewFaceEntries=$this->getFacesFromImage($flaggedForKEEP);
		while ($oldFace = $resultOldFaceEntries->fetch()) {
			$isDeleted = false;
			while ($newFace = $resultNewFaceEntries->fetch()){
				if ($this->isSameFace($oldFace, $newFace)){
					$this->updatePersonAndDeleteFace($oldFace['id'], $newFace['id']);
					$isDeleted = true;
					break;
				}
			}
			if(!$isDeleted){
				$this->updateFaceToNewImage($oldFace['id'], $flaggedForKEEP);
			}
		}
	}

	/**
	 * Get Nextcloud internal file ids which are duplicated by the same model
	 * @return IResult Columns: file, model
	 */
	protected function getDuplicatedFiles(): IResult {
		return $this->connection->getQueryBuilder()
			->select('nc_file_id', 'model')
			->from('facerecog_images')
			->groupBy('nc_file_id','model')
			->having('COUNT(*) > 1')
			->executeQuery();
	}

	/**
	 * Get Image based on NC file ID and the model ID
	 * @param int $ncFileId nextcloud file ID
	 * @param int $modelNumber model ID
	 * @return IResult
	 */
	protected function getImagesByModelAndNcFileId($ncFileId, $modelNumber): IResult {
		return $this->connection->getQueryBuilder()
			->select('*')
			->from('facerecog_images')
			->Where('nc_file_id = ? AND model = ?')
			->setParameters([
				$ncFileId,
				$modelNumber
			])
			->executeQuery();
	}
	
	/**
	 * Get Face based on image ID
	 * @param int $imageId
	 * @return IResult
	 */
	protected function getFacesFromImage($imageId): ?IResult {
		$builder = $this->connection->getQueryBuilder();
		return $builder
			->select('*')
			->from('facerecog_faces')
			->Where($builder->expr()->eq('image_id', $builder->createNamedParameter($imageId)))
			->executeQuery();
	}

	/**
	 * Update faces of the image to be connected to the image what we keep after the deduplication
	 * @param $imageId the old image ID
	 * @param $flaggedForKEEP new image ID what is replaced to
	 */
	protected function updateFaceToNewImage($faceId, $newImageId): void {
		$builder = $this->connection->getQueryBuilder();
		$builder
			->update('facerecog_faces')
			->set('image_id', $builder->createNamedParameter($newImageId))
			->Where($builder->expr()->eq('id', $builder->createNamedParameter($faceId)))
			->executeStatement();
	}

	/**
	 * If the face is duplicate, update the person to be connected to the face we keep and delete the old face
	 * @param $oldFaceId the old face ID
	 * @param $newFaceId new face ID what is replaced to
	 */
	protected function updatePersonAndDeleteFace($oldFaceId, $newFaceId): void {
		$builder = $this->connection->getQueryBuilder();
		$builder
			->update('facerecog_cluster_faces')
			->set('face_id', $builder->createNamedParameter($newFaceId))
			->Where($builder->expr()->eq('face_id', $builder->createNamedParameter($oldFaceId)))
			->executeStatement();
		
		$delete = $this->connection->getQueryBuilder();
		$delete
			->delete('facerecog_faces')
			->where($delete->expr()->eq('id', $delete->createNamedParameter($oldFaceId)))
			->executeStatement();
	}
	
	/**
	 * Check if 2 face are the same.
	 * Checked fields:
	 * - x
	 * - y
	 * - width
	 * - height
	 * - confidence
	 * - landmarks
	 * - descriptor
	 * @param $oldFace the old face complete database row
	 * @param $newFace new face complete database row
	 * @return bool True if the are equal otherwise false
	 */
	protected function isSameFace($oldFace, $newFace): bool {
		return
			$oldFace['x']==$newFace['x'] &&
			$oldFace['y']==$newFace['y'] &&
			$oldFace['width']==$newFace['width'] &&
			$oldFace['height']==$newFace['height'] &&
			$oldFace['confidence']==$newFace['confidence'] &&
			$oldFace['landmarks']==$newFace['landmarks']  &&
			$oldFace['descriptor']==$newFace['descriptor'];
	}

	/**
	 * Remove image by image ID
	 * @param $imageId image ID what is removed
	 */
	protected function removeImage($imageId): void {
		$delete = $this->connection->getQueryBuilder();
		$delete
			->delete('facerecog_images')
			->where($delete->expr()->eq('id', $delete->createNamedParameter($imageId)))
			->executeStatement();
	}
}

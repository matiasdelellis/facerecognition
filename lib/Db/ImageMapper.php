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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ImageMapper extends QBMapper {
	/** @var FaceMapper Face mapper*/
	private $faceMapper;

	public function __construct(IDBConnection $db, FaceMapper $faceMapper) {
		parent::__construct($db, 'facerecog_images', '\OCA\FaceRecognition\Db\Image');
		$this->faceMapper = $faceMapper;
	}

	/**
	 * @param string $userId Id of user
	 * @param int $imageId Id of Image to get
	 *
	 */
	public function find(string $userId, int $imageId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file', 'is_processed', 'is_refined', 'error', 'last_processed_time', 'processing_duration')
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($imageId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Id of model to get
	 *
	 */
	public function findAll(string $userId, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file', 'is_processed', 'is_refined', 'error', 'last_processed_time', 'processing_duration')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)));
		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId Id of user
	 * @param int $modelId Id of model
	 * @param int $fileId Id of file to get Image
	 *
	 */
	public function findFromFile(string $userId, int $modelId, int $fileId): ?Image {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'is_processed', 'is_refined', 'error')
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andwhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('file', $qb->createNamedParameter($fileId)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function imageExists(Image $image): ?int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select(['id'])
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('file', $qb->createParameter('file')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $image->getUser())
			->setParameter('file', $image->getFile())
			->setParameter('model', $image->getModel());
		$resultStatement = $query->executeQuery();
		$row = $resultStatement->fetch();
		$resultStatement->closeCursor();
		return $row ? (int)$row['id'] : null;
	}

	public function countImages(int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('model', $model);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function countProcessedImages(int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			->setParameter('model', $model)
			->setParameter('is_processed', True);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Images that are fully analyzed: processed and refined with the current
	 * model at maximum quality. When the refinement is disabled, being
	 * processed is enough, so the progress counts the processed ones instead.
	 *
	 * @return int Images that have nothing pending
	 */
	public function countRefinedImages(int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_refined', $qb->createParameter('is_refined')))
			->setParameter('model', $model)
			->setParameter('is_refined', True, IQueryBuilder::PARAM_BOOL);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Images of the user that were refined: processed and analyzed again with
	 * the current model at maximum quality.
	 *
	 * @return int
	 */
	public function countUserRefinedImages(string $userId, int $model): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->andWhere($qb->expr()->eq('is_refined', $qb->createParameter('is_refined')))
			->setParameter('user', $userId)
			->setParameter('model', $model)
			->setParameter('is_refined', True, IQueryBuilder::PARAM_BOOL);
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * Average time an image took to be analyzed, in milliseconds.
	 *
	 * The two passes cost very different amounts: the fast one works on a small
	 * image with the HOG model, the refinement one on a full-size image with the
	 * current model. Averaging both together gives a time that is far too
	 * optimistic for the refinement that is left, which is the only work
	 * remaining once the fast pass went over the library. So $refined asks for
	 * the duration of the refined images only, which is what that work costs.
	 *
	 * While no image was refined yet there is nothing to average, and the fast
	 * pass durations are all there is. They underestimate the refinement, but it
	 * is the only estimate available, and it is right again as soon as the first
	 * image is refined.
	 *
	 * @param int $model Model to get the average of
	 * @param bool $refined Whether to average the refined images only
	 *
	 * @return int Average duration in milliseconds, 0 when there is nothing to average
	 */
	public function avgProcessingDuration(int $model, bool $refined = false): int {
		$duration = $this->queryAvgProcessingDuration($model, $refined);
		if ($refined && $duration === 0) {
			$duration = $this->queryAvgProcessingDuration($model, false);
		}
		return $duration;
	}

	private function queryAvgProcessingDuration(int $model, bool $refined): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('AVG(' . $qb->getColumnName('processing_duration') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('model', $model);
		if ($refined) {
			$query->andWhere($qb->expr()->eq('is_refined', $qb->createParameter('is_refined')))
			      ->setParameter('is_refined', True, IQueryBuilder::PARAM_BOOL);
		} else {
			$query->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			      ->setParameter('is_processed', True);
		}
		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	public function countUserImages(string $userId, int $model, bool $processed = false): int {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $userId)
			->setParameter('model', $model);

		if ($processed) {
			$query->andWhere($qb->expr()->eq('is_processed', $qb->createParameter('is_processed')))
			      ->setParameter('is_processed', true);
		}

		$resultStatement = $query->executeQuery();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return (int)$data[0];
	}

	/**
	 * @param IUser|null $user User for which to get images for. If not given, all images from instance are returned.
	 * @param int $modelId Model Id to get images for.
	 */
	public function findImagesWithoutFaces(?IUser $user, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select(['id', 'user', 'file', 'model'])
			->from($this->getTableName())
			->where($qb->expr()->eq('is_processed',  $qb->createParameter('is_processed')))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->setParameter('is_processed', false, IQueryBuilder::PARAM_BOOL);
		if (!is_null($user)) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($user->getUID())));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Images that have to be analyzed in the current run: the ones that were
	 * never processed, and the ones that were only analyzed in the fast pass and
	 * still have to be refined with the current model at maximum quality.
	 *
	 * An image that failed is left out, exactly as it was before the refinement
	 * existed: a failure leaves the image processed, not refined and with its
	 * error recorded, and taking it again on every run would mean retrying the
	 * files that can never be analyzed for as long as they exist, competing for
	 * the time of every run. It keeps whatever the fast pass found and waits for
	 * an explicit `occ face:reset --error`.
	 *
	 * @param IUser|null $user User for which to get images for. If not given, all images from instance are returned.
	 * @param int $modelId Model Id to get images for.
	 */
	public function findImagesToProcess(?IUser $user, int $modelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select(['id', 'user', 'file', 'model'])
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('is_processed', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
				$qb->expr()->andX(
					$qb->expr()->eq('is_refined', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
					$qb->expr()->isNull('error')
				)
			));
		if (!is_null($user)) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($user->getUID())));
		}
		return $this->findEntities($qb);
	}

	public function findImages(string $userId, int $model): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.file')
			->from($this->getTableName(), 'i')
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)));

		$images = $this->findEntities($qb);
		return $images;
	}

	public function findFromPersonLike(string $userId, int $model, string $name, $offset = null, $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.id', 'i.file')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('f.cluster', 'c.id'))
			->innerJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('c.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->like($qb->func()->lower('p.name'), $qb->createParameter('query')));

		$query = '%' . $this->db->escapeLikeParameter(strtolower($name)) . '%';
		$qb->setParameter('query', $query);

		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	public function findFromPerson(string $userId, int $modelId, string $name, $offset = null, $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.file')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('f.cluster', 'c.id'))
			->innerJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('c.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)))
			->orderBy('i.file', 'DESC');

		$qb->setFirstResult($offset);
		$qb->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	public function countFromPerson(string $userId, int $modelId, string $name): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'facerecog_faces', 'f', $qb->expr()->eq('f.image', 'i.id'))
			->innerJoin('f', 'facerecog_clusters', 'c', $qb->expr()->eq('f.cluster', 'c.id'))
			->innerJoin('c', 'facerecog_persons', 'p', $qb->expr()->eq('c.person', 'p.id'))
			->where($qb->expr()->eq('p.user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('i.model', $qb->createNamedParameter($modelId)))
			->andWhere($qb->expr()->eq('i.is_processed', $qb->createNamedParameter(True)))
			->andWhere($qb->expr()->eq('p.name', $qb->createNamedParameter($name)));

		$result = $qb->executeQuery();
		$column = (int)$result->fetchOne();
		$result->closeCursor();

		return $column;
	}

	/**
	 * Writes to DB that image has been processed. Previously found faces are deleted and new ones are inserted.
	 * If there is exception, its stack trace is also updated.
	 *
	 * The faces are only replaced on success. On a failure the old faces are
	 * left alone, so an image that already had faces keeps them, and with them
	 * the person: what the fast pass found is not lost because the refinement
	 * failed. The image also stays not refined, but that alone does not put it
	 * back in the queue, see findImagesToProcess(). The same happens when
	 * $replaceFaces is false, which is what the "image too small" skip uses to
	 * keep whatever was found before.
	 *
	 * @param Image $image Image to be updated
	 * @param Face[] $faces Faces to insert
	 * @param int $duration Processing time, in milliseconds
	 * @param \Exception|null $e Any exception that happened during image processing
	 * @param bool $refined Whether this was the second, high quality pass that replaces the fast-pass faces
	 * @param bool $replaceFaces Whether to replace the old faces with the new ones. False keeps them.
	 *
	 * @return void
	 */
	public function imageProcessed(Image $image, array $faces, int $duration, ?\Exception $e = null, bool $refined = false, bool $replaceFaces = true): void {
		$this->db->beginTransaction();
		try {
			// Update image itself
			//
			$error = null;
			if ($e !== null) {
				$error = substr($e->getMessage(), 0, 1024);
			}

			// A failure leaves the image not refined, so that it is taken again
			// on the next run. A success is refined according to the pass.
			$isRefined = $refined && $e === null;

			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set("is_processed", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set("is_refined", $qb->createNamedParameter($isRefined, IQueryBuilder::PARAM_BOOL))
				->set("error", $qb->createNamedParameter($error))
				->set("last_processed_time", $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
				->set("processing_duration", $qb->createNamedParameter($duration))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($image->id)))
				->executeStatement();

			if ($e === null && $replaceFaces) {
				// Delete all previous faces, to replace them with the new ones.
				//
				$qb = $this->db->getQueryBuilder();
				$qb->delete('facerecog_faces')
					->where($qb->expr()->eq('image', $qb->createNamedParameter($image->id)))
					->executeStatement();

				// Insert all faces
				//
				foreach ($faces as $face) {
					$this->faceMapper->insertFace($face, $this->db);
				}
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
	 * The file changed, so nothing that was obtained from it holds any more: the
	 * image goes back to not processed and not refined, and both passes take it
	 * again. Leaving it refined would count it as finished while it is pending.
	 *
	 * @param Image $image Image to reset
	 *
	 * @return void
	 */
	public function resetImage(Image $image): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("is_refined", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("error", $qb->createNamedParameter(null))
			->set("last_processed_time", $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($image->getUser())))
			->andWhere($qb->expr()->eq('file', $qb->createNamedParameter($image->getFile())))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($image->getModel())))
			->executeStatement();
	}

	/**
	 * Marks all the images of the user and model as not refined, so the
	 * refinement pass analyzes them again (e.g. after increasing the size of
	 * the images). The faces and clusters are kept, and the re-processing
	 * replaces the faces and inherits their clusters.
	 *
	 * @param string $userId User to reset the refinement of
	 * @param int $modelId Model to reset the refinement of
	 *
	 * @return void
	 */
	public function resetRefined(string $userId, int $modelId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_refined", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->executeStatement();
	}

	/**
	 * Resets all image with error from that user and prepares it to be processed again
	 *
	 * @param string $userId User to reset errors
	 *
	 * @return void
	 */
	public function resetErrors(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set("is_processed", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set("error", $qb->createNamedParameter(null))
			->set("last_processed_time", $qb->createNamedParameter(null))
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('error'))
			->executeStatement();
	}

	/**
	 * Deletes all images from that user.
	 *
	 * @param string $userId User to drop images from table.
	 *
	 * @return void
	 */
	public function deleteUserImages(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/**
	 * Deletes all images from that user and Model
	 *
	 * @param string $userId User to drop images from table.
	 * @param int $modelId model to drop images from table.
	 *
	 * @return void
	 */
	public function deleteUserModel(string $userId, $modelId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($modelId)))
			->executeStatement();
	}

}

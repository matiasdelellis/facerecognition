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

class ImageMapper extends Mapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'face_recognition_images', '\OCA\FaceRecognition\Db\Image');
	}

	public function imageExists(IUser $user, $file, $model) {
		$qb = $this->db->getQueryBuilder();
		$query = $qb
			->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('face_recognition_images')
			->where($qb->expr()->eq('user', $qb->createParameter('user')))
			->andWhere($qb->expr()->eq('file', $qb->createParameter('file')))
			->andWhere($qb->expr()->eq('model', $qb->createParameter('model')))
			->setParameter('user', $user->getUID())
			->setParameter('file', $file->getId())
			->setParameter('model', $model);
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		return ((int)$data[0] > 0);
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
}
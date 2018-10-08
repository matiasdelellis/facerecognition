<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition;

use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\FaceNewMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Helper\Requirements;
use OCA\FaceRecognition\Migration\AddDefaultFaceModel;

class Watcher {

	/** @var IConfig Config */
	private $config;

	/** @var IDBConnection */
	private $connection;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/** @var FaceNewMapper */
	private $faceNewMapper;

	/** @var ImageMapper */
	private $imageMapper;

	/** @var PersonMapper */
	private $personMapper;

	/**
	 * Watcher constructor.
	 *
	 * @param IConfig $config
	 * @param IDBConnection $connection
	 * @param IUserManager $userManager
	 * @param FaceMapper $faceMapper
	 * @param FaceNewMapper $faceNewMapper
	 * @param ImageMapper $imageMapper
	 * @param PersonMapper $personMapper
	 */
	public function __construct(IConfig       $config,
								IDBConnection $connection,
								IUserManager  $userManager,
								FaceMapper    $faceMapper,
								FaceNewMapper $faceNewMapper,
								ImageMapper   $imageMapper,
								PersonMapper  $personMapper) {
		$this->config = $config;
		$this->connection = $connection;
		$this->userManager = $userManager;
		$this->faceMapper = $faceMapper;
		$this->faceNewMapper = $faceNewMapper;
		$this->imageMapper = $imageMapper;
		$this->personMapper = $personMapper;
	}

	/**
	 * A node has been updated. We just store the file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function postWrite(Node $node) {
		// v1 code
		$absPath = ltrim($node->getPath(), '/');
		$owner = explode('/', $absPath)[0];

		if (!$this->userManager->userExists($owner) || $node instanceof Folder) {
			return;
		}


		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

//		if ($this->faceMapper->fileExists($node->getId())) {
//			return;
//		}

		$face = new Face();
		$face->setUid($owner);
		$face->setFile($node->getId());
		$face->setName('unknown');
		$face->setDistance(-1.0);
		$face->setTop(-1.0);
		$face->setRight(-1.0);
		$face->setBottom(-1.0);
		$face->setLeft(-1.0);
		$this->faceMapper->insert($face);

	}

	/**
	 * @param Node $node
	 */
	public function postWritev2(Node $node) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// todo: should we also care about this too: instanceOfStorage(ISharedStorage::class);
		if ($node->getStorage()->instanceOfStorage(IHomeStorage::class) == false) {
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		// todo: what if file was image and now its mimetype is changed?:) we should remove it
		// todo: honor .nomedia here
		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		$owner = $node->getOwner()->getUid();

		if (!$this->userManager->userExists($owner)) {
			return;
		}

		$image = new Image();
		$image->setUser($owner);
		$image->setFile($node->getId());
		$image->setModel($model);

		$imageId = $this->imageMapper->imageExists($image);
		if ($imageId == null) {
			// todo: can we have larger transaction with bulk insert?
			$this->imageMapper->insert($image);
		} else {
			$this->imageMapper->resetImage($image);
			// note that invalidatePersons depends on existence of faces for a given image,
			// and we must invalidate before we delete faces!
			$this->personMapper->invalidatePersons($imageId);
			$this->faceNewMapper->removeFaces($imageId);
		}
	}

	/**
	 * A node has been delete. Remove faces with file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function preDelete(Node $node) {
		$absPath = ltrim($node->getPath(), '/');
		$owner = explode('/', $absPath)[0];

		if (!$this->userManager->userExists($owner) || $node instanceof Folder) {
			return;
		}

		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		try {
			$faces = $this->faceMapper->findFile($owner, $node->getId());
		} catch(Exception $e) {
			return;
		}

		foreach ($faces as $face) {
			$this->faceMapper->delete($face);
		}
	}

	public function postDeletev2(Node $node) {
		$model = intval($this->config->getAppValue('facerecognition', 'model', AddDefaultFaceModel::DEFAULT_FACE_MODEL_ID));

		// todo: should we also care about this too: instanceOfStorage(ISharedStorage::class);
		if ($node->getStorage()->instanceOfStorage(IHomeStorage::class) == false) {
			return;
		}

		if ($node instanceof Folder) {
			return;
		}

		// todo: what if file was image and now its mimetype is changed?:) we should remove it
		if (!Requirements::isImageTypeSupported($node->getMimeType())) {
			return;
		}

		$owner = $node->getOwner()->getUid();

		$image = new Image();
		$image->setUser($owner);
		$image->setFile($node->getId());
		$image->setModel($model);

		$imageId = $this->imageMapper->imageExists($image);
		if ($imageId != null) {
			// note that invalidatePersons depends on existence of faces for a given image,
			// and we must invalidate before we delete faces!
			$this->personMapper->invalidatePersons($imageId);
			$this->faceNewMapper->removeFaces($imageId);

			$image->setId($imageId);
			$this->imageMapper->delete($image);
		}
	}
}

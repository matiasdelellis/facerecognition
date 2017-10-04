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
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

class Watcher {

	/** @var IDBConnection */
	private $connection;

	/** @var IUserManager */
	private $userManager;

	/** @var FaceMapper */
	private $faceMapper;

	/**
	 * Watcher constructor.
	 *
	 * @param IDBConnection $connection
	 * @param IUserManager $userManager
	 * @param FaceMapper $faceMapper
	 */
	public function __construct(IDBConnection $connection,
		                    IUserManager  $userManager,
		                    FaceMapper    $faceMapper) {
		$this->connection = $connection;
		$this->userManager = $userManager;
		$this->faceMapper = $faceMapper;
	}

	/**
	 * A node has been updated. We just store the file id
	 * with the current user in the DB
	 *
	 * @param Node $node
	 */
	public function postWrite(Node $node) {
		$absPath = ltrim($node->getPath(), '/');
		$owner = explode('/', $absPath)[0];

		if (!$this->userManager->userExists($owner) || $node instanceof Folder) {
			return;
		}

		if ($node->getMimeType() !== 'image/jpeg' && $node->getMimeType() !== 'image/png') {
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

		if ($node->getMimeType() !== 'image/jpeg' && $node->getMimeType() !== 'image/png') {
			return;
		}

		try {
			$faces = $this->faceMapper->findFile($node->getId());
		} catch(Exception $e) {
			return;
		}

		foreach ($faces as $face) {
			$this->faceMapper->delete($face);
		}

	}


}

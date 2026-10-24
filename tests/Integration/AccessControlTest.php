<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Tests\Integration;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

/**
 * The ids of faces and clusters come from the client. They have to be checked
 * against the user in the database, not only in the interface.
 *
 * @group DB
 */
class AccessControlTest extends IntegrationTestCase {

	private const MODEL_ID = 1;

	/** @var IUser|null */
	private $otherUser = null;

	public function tearDown(): void {
		if (!is_null($this->otherUser)) {
			$this->otherUser->delete();
			$this->otherUser = null;
		}

		parent::tearDown();
	}

	private function createImage(string $userId): Image {
		$image = new Image();
		$image->setUser($userId);
		$image->setFile(random_int(1000000, PHP_INT_MAX));
		$image->setModel(self::MODEL_ID);
		$image->setIsProcessed(true);
		$image->setIsRefined(true);

		return $this->container->query(ImageMapper::class)->insert($image);
	}

	private function createFace(Image $image): Face {
		$face = Face::fromModel($image->getId(), [
			'left' => 10,
			'top' => 20,
			'right' => 60,
			'bottom' => 80,
			'detection_confidence' => 0.99,
			'landmarks' => [],
			'descriptor' => [0.1, 0.2],
		]);

		return $this->container->query(FaceMapper::class)->insertFace($face);
	}

	public function testFaceFromAnotherUserIsNotFound() {
		$userManager = $this->container->query(IUserManager::class);
		$otherUser = $userManager->createUser('other' . random_int(1, PHP_INT_MAX), 'Test-Password-123!');
		$this->assertNotFalse($otherUser);
		$this->otherUser = $otherUser;

		$otherImage = $this->createImage($this->otherUser->getUID());
		$otherFace = $this->createFace($otherImage);

		$faceMapper = $this->container->query(FaceMapper::class);
		$this->assertNull($faceMapper->findForUser($this->user->getUID(), $otherFace->getId()));
		$this->assertNotNull($faceMapper->findForUser($this->otherUser->getUID(), $otherFace->getId()));
	}

	public function testDetachFaceFromAnotherUserIsRejected() {
		$userManager = $this->container->query(IUserManager::class);
		$otherUser = $userManager->createUser('other' . random_int(1, PHP_INT_MAX), 'Test-Password-123!');
		$this->assertNotFalse($otherUser);
		$this->otherUser = $otherUser;

		$otherImage = $this->createImage($this->otherUser->getUID());
		$otherFace = $this->createFace($otherImage);

		$clusterMapper = $this->container->query(ClusterMapper::class);
		$clusterId = $clusterMapper->create($this->user->getUID(), self::MODEL_ID);

		$this->expectException(DoesNotExistException::class);
		$clusterMapper->detachFace($this->user->getUID(), $clusterId, $otherFace->getId());
	}

	public function testDetachFaceFromAnotherClusterIsRejected() {
		$image = $this->createImage($this->user->getUID());
		$face = $this->createFace($image);

		$clusterMapper = $this->container->query(ClusterMapper::class);
		$ownClusterId = $clusterMapper->create($this->user->getUID(), self::MODEL_ID);
		$otherClusterId = $clusterMapper->create($this->user->getUID(), self::MODEL_ID);
		$clusterMapper->attachFaces([$face->getId()], $ownClusterId);

		$this->expectException(DoesNotExistException::class);
		$clusterMapper->detachFace($this->user->getUID(), $otherClusterId, $face->getId());
	}
}

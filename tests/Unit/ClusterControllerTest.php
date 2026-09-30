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
namespace OCA\FaceRecognition\Tests\Unit;

use Test\TestCase;

use OCP\IRequest;
use OCP\AppFramework\Http;

use OCA\FaceRecognition\Controller\ClusterController;

use OCA\FaceRecognition\Db\Cluster;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\ClusterLinkService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

/**
 * Naming a cluster, and naming a single face of it: the face comes from the
 * request, and it has to be checked on its own.
 */
class ClusterControllerTest extends TestCase {

	private const USER = 'alice';

	/** @var FaceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $faceMapper;
	/** @var ImageMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $imageMapper;
	/** @var ClusterMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $clusterMapper;
	/** @var PersonMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $personMapper;
	/** @var ClusterController */
	private $controller;

	public function setUp(): void {
		parent::setUp();

		$this->faceMapper    = $this->createMock(FaceMapper::class);
		$this->imageMapper   = $this->createMock(ImageMapper::class);
		$this->clusterMapper = $this->createMock(ClusterMapper::class);
		$this->personMapper  = $this->createMock(PersonMapper::class);

		$this->controller = new ClusterController(
			'facerecognition',
			$this->createMock(IRequest::class),
			$this->faceMapper,
			$this->imageMapper,
			$this->clusterMapper,
			$this->personMapper,
			$this->createMock(ClusterLinkService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(UrlService::class),
			self::USER
		);

		// The cluster 3 is the user's.
		$this->clusterMapper->method('find')->with(self::USER, 3)->willReturn($this->makeCluster(3, null));
	}

	private function makeCluster(int $id, ?int $personId): Cluster {
		$cluster = new Cluster();
		$cluster->setId($id);
		$cluster->setUser(self::USER);
		$cluster->setModel(1);
		$cluster->setPerson($personId);
		return $cluster;
	}

	private function makeFace(int $id, int $imageId, ?int $clusterId): Face {
		$face = new Face();
		$face->setId($id);
		$face->setImage($imageId);
		$face->setCluster($clusterId);
		return $face;
	}

	private function makePerson(int $id, string $name): Person {
		$person = new Person();
		$person->setId($id);
		$person->setName($name);
		return $person;
	}

	/** Nothing may be written: not the face, not the cluster, not a person. */
	private function expectNothingWritten(): void {
		$this->clusterMapper->expects($this->never())->method('detachFace');
		$this->clusterMapper->expects($this->never())->method('setPerson');
		$this->faceMapper->expects($this->never())->method('markFaceManual');
		$this->personMapper->expects($this->never())->method('findOrCreateByName');
		$this->personMapper->expects($this->never())->method('deleteOrphaned');
	}

	/**
	 * The cluster is the user's, but the face is on the image of somebody
	 * else: the request is refused and nothing changes.
	 */
	public function testAFaceOfAnotherUserIsRefused() {
		$this->faceMapper->method('find')->with(100)->willReturn($this->makeFace(100, 77, 3));
		// The image 77 is not one of the user's.
		$this->imageMapper->method('find')->with(self::USER, 77)->willReturn(null);
		$this->expectNothingWritten();

		$resp = $this->controller->updateName(3, 'Bob', 100);

		$this->assertEquals(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testAFaceThatDoesNotExistIsRefused() {
		$this->faceMapper->method('find')->willReturn(null);
		$this->expectNothingWritten();

		$resp = $this->controller->updateName(3, 'Bob', 404);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	/**
	 * A face that is not in the cluster any more, because the clustering
	 * moved it since the client looked, is refused: detaching it would change
	 * a cluster it is not in.
	 */
	public function testAFaceOfAnotherClusterIsRefused() {
		$this->faceMapper->method('find')->willReturn($this->makeFace(100, 10, 5));
		$this->imageMapper->method('find')->willReturn(new Image());
		$this->expectNothingWritten();

		$resp = $this->controller->updateName(3, 'Bob', 100);

		$this->assertEquals(Http::STATUS_CONFLICT, $resp->getStatus());
	}

	/**
	 * An own face of the cluster is moved to the person, alone, and marked
	 * manual, so that analyzing the photo again does not undo it.
	 */
	public function testAnOwnFaceIsMovedAloneAndMarkedManual() {
		$this->faceMapper->method('find')->with(100)->willReturn($this->makeFace(100, 10, 3));
		$this->imageMapper->method('find')->with(self::USER, 10)->willReturn(new Image());
		$this->personMapper->method('findOrCreateByName')->with(self::USER, 'Bob')->willReturn($this->makePerson(5, 'Bob'));
		$this->personMapper->method('find')->willReturn($this->makePerson(5, 'Bob'));

		$this->clusterMapper->expects($this->once())->method('detachFace')
			->with(3, 100, 5)
			->willReturn($this->makeCluster(8, 5));
		$this->clusterMapper->expects($this->never())->method('setPerson');
		$this->faceMapper->expects($this->once())->method('markFaceManual')->with(100);

		$resp = $this->controller->updateName(3, 'Bob', 100);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(8, $resp->getData()['id']);
		$this->assertEquals('Bob', $resp->getData()['name']);
	}

	// --- detachFace -------------------------------------------------------

	/**
	 * Detaching takes the face out of a cluster of the user, and the face has
	 * to be checked as much as the cluster: one of somebody else is refused.
	 */
	public function testDetachingAFaceOfAnotherUserIsRefused() {
		$this->faceMapper->method('find')->with(100)->willReturn($this->makeFace(100, 77, 3));
		$this->imageMapper->method('find')->with(self::USER, 77)->willReturn(null);
		$this->expectNothingWritten();

		$resp = $this->controller->detachFace(3, 100, 'Bob');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testDetachingAFaceThatDoesNotExistIsRefused() {
		$this->faceMapper->method('find')->willReturn(null);
		$this->expectNothingWritten();

		$resp = $this->controller->detachFace(3, 404);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testDetachingAFaceOfAnotherClusterIsRefused() {
		$this->faceMapper->method('find')->willReturn($this->makeFace(100, 10, 5));
		$this->imageMapper->method('find')->willReturn(new Image());
		$this->expectNothingWritten();

		$resp = $this->controller->detachFace(3, 100);

		$this->assertEquals(Http::STATUS_CONFLICT, $resp->getStatus());
	}

	public function testAnOwnFaceIsDetached() {
		$this->faceMapper->method('find')->with(100)->willReturn($this->makeFace(100, 10, 3));
		$this->imageMapper->method('find')->with(self::USER, 10)->willReturn(new Image());
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Bob'));
		$this->personMapper->method('find')->willReturn($this->makePerson(5, 'Bob'));

		$this->clusterMapper->expects($this->once())->method('detachFace')
			->with(3, 100, 5)
			->willReturn($this->makeCluster(8, 5));

		$resp = $this->controller->detachFace(3, 100, 'Bob');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(8, $resp->getData()['id']);
	}

	/**
	 * Without a face the whole cluster goes to the person, and no face is
	 * looked at at all.
	 */
	public function testWithoutAFaceTheWholeClusterIsNamed() {
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Bob'));
		$this->personMapper->method('find')->willReturn($this->makePerson(5, 'Bob'));

		$this->clusterMapper->expects($this->once())->method('setPerson')->with(3, 5);
		$this->clusterMapper->expects($this->never())->method('detachFace');
		$this->faceMapper->expects($this->never())->method('find');
		$this->faceMapper->expects($this->never())->method('markFaceManual');

		$resp = $this->controller->updateName(3, 'Bob');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
	}
}

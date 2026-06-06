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
use OCP\Files\File;
use OCP\AppFramework\Http;

use OCA\FaceRecognition\Controller\ApiController;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

/**
 * Validation and edge-case coverage for the manual-face API endpoints
 * (addManualFace / reassignFace). The collaborators are mocked and real
 * entities are used as fixtures (their getters are magic __call methods,
 * which PHPUnit mocks cannot stub), so these tests exercise the controller
 * logic only — no database is touched.
 */
class ManualFaceApiTest extends TestCase {

	private const USER = 'alice';

	/** @var FaceMapper */
	private $faceMapper;
	/** @var ImageMapper */
	private $imageMapper;
	/** @var PersonMapper */
	private $personMapper;
	/** @var SettingsService */
	private $settingsService;
	/** @var UrlService */
	private $urlService;
	/** @var ApiController */
	private $controller;

	public function setUp(): void {
		parent::setUp();

		$request               = $this->createMock(IRequest::class);
		$this->faceMapper      = $this->createMock(FaceMapper::class);
		$this->imageMapper     = $this->createMock(ImageMapper::class);
		$this->personMapper    = $this->createMock(PersonMapper::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->urlService      = $this->createMock(UrlService::class);

		$this->controller = new ApiController(
			'facerecognition',
			$request,
			$this->faceMapper,
			$this->imageMapper,
			$this->personMapper,
			$this->settingsService,
			$this->urlService,
			self::USER
		);
	}

	private function enableUser(): void {
		$this->settingsService->method('getUserEnabled')->willReturn(true);
		$this->settingsService->method('getCurrentFaceModel')->willReturn(1);
	}

	private function makePerson(int $id, string $name): Person {
		$person = new Person();
		$person->setId($id);
		$person->setName($name);
		return $person;
	}

	// --- addManualFace ----------------------------------------------------

	public function testAddManualFaceRejectsDisabledUser() {
		$this->settingsService->method('getUserEnabled')->willReturn(false);
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $resp->getStatus());
	}

	public function testAddManualFaceRejectsEmptyName() {
		$this->enableUser();
		$resp = $this->controller->addManualFace(42, '   ', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsInvalidImageDimensions() {
		$this->enableUser();
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 0, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsOutOfBoundsRectangle() {
		$this->enableUser();
		// x + width = 1.6, which spills outside the right edge of the image.
		$resp = $this->controller->addManualFace(42, 'Alice', 0.8, 0.1, 0.8, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsZeroAreaBox() {
		$this->enableUser();
		// width 0.001 of a 100px image rounds down to 0 pixels -> degenerate face.
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.001, 0.2, 100, 1000, false);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertEquals('rectangle too small', $resp->getData()['error']);
	}

	public function testAddManualFaceRejectsInaccessibleFile() {
		$this->enableUser();
		$this->urlService->method('getFileNode')->willReturn(null);
		$resp = $this->controller->addManualFace(999, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);
		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testAddManualFaceHappyPathQueuesClusteringWhenRequested() {
		$this->enableUser();

		$file = $this->createMock(File::class);
		$this->urlService->method('getFileNode')->with(42)->willReturn($file);

		$image = new Image();
		$image->setId(10);
		$this->imageMapper->method('findFromFile')->willReturn($image);

		$this->personMapper->method('findByName')->willReturn([$this->makePerson(5, 'Alice')]);

		$this->faceMapper->method('insertManualFace')
			->willReturnCallback(function (Face $face) {
				$face->setId(100);
				return $face;
			});

		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, true);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(100, $data['faceId']);
		$this->assertEquals(5, $data['personId']);
		$this->assertEquals('Alice', $data['name']);
		// useForClustering=true: the face is queued for the background descriptor
		// task, which will decide whether a face is actually there.
		$this->assertTrue($data['clusteringQueued']);
	}

	public function testAddManualFaceDoesNotQueueClusteringByDefault() {
		$this->enableUser();

		$file = $this->createMock(File::class);
		$this->urlService->method('getFileNode')->with(42)->willReturn($file);

		$image = new Image();
		$image->setId(10);
		$this->imageMapper->method('findFromFile')->willReturn($image);

		$this->personMapper->method('findByName')->willReturn([$this->makePerson(5, 'Alice')]);

		$this->faceMapper->method('insertManualFace')
			->willReturnCallback(function (Face $face) {
				$face->setId(100);
				return $face;
			});

		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000, false);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertFalse($resp->getData()['clusteringQueued']);
	}

	// --- reassignFace -----------------------------------------------------

	public function testReassignFaceRejectsEmptyName() {
		$this->enableUser();
		$resp = $this->controller->reassignFace(100, '  ');
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testReassignFaceReturnsNotFoundForMissingFace() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn(null);
		$resp = $this->controller->reassignFace(404, 'Alice');
		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testReassignFaceForbidsForeignImage() {
		$this->enableUser();

		$face = new Face();
		$face->setImage(77);
		$this->faceMapper->method('find')->willReturn($face);

		// Image does not belong to the current user.
		$this->imageMapper->method('find')->willReturn(null);

		$resp = $this->controller->reassignFace(100, 'Alice');
		$this->assertEquals(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testReassignFaceHappyPath() {
		$this->enableUser();

		$face = new Face();
		$face->setImage(10);
		$this->faceMapper->method('find')->willReturn($face);

		$this->imageMapper->method('find')->willReturn(new Image());

		$this->personMapper->method('findByName')->willReturn([$this->makePerson(5, 'Bob')]);

		$this->faceMapper->expects($this->once())
			->method('reassignFace')
			->with(100, 5);

		$resp = $this->controller->reassignFace(100, 'Bob');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(100, $data['faceId']);
		$this->assertEquals(5, $data['personId']);
		$this->assertEquals('Bob', $data['name']);
	}
}

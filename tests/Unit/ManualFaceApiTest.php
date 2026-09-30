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

use Psr\Log\LoggerInterface;

use OCA\FaceRecognition\Controller\ApiController;

use OCA\FaceRecognition\Db\Cluster;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\ManualRegion;
use OCA\FaceRecognition\Db\ManualRegionMapper;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

/**
 * Validation and edge case coverage of the manual face endpoints
 * (addManualFace / addManualRegion / getFacesForFile / detachFace). The
 * collaborators are mocked and real entities are used as fixtures, since their
 * getters are magic __call methods that a PHPUnit mock cannot stub, so what is
 * exercised here is the logic of the controller alone and no database is
 * touched.
 */
class ManualFaceApiTest extends TestCase {

	private const USER = 'alice';

	/** @var FaceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $faceMapper;
	/** @var ImageMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $imageMapper;
	/** @var ClusterMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $clusterMapper;
	/** @var PersonMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $personMapper;
	/** @var SettingsService|\PHPUnit\Framework\MockObject\MockObject */
	private $settingsService;
	/** @var UrlService|\PHPUnit\Framework\MockObject\MockObject */
	private $urlService;
	/** @var ManualRegionMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $manualRegionMapper;
	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;
	/** @var ManualFaceService|\PHPUnit\Framework\MockObject\MockObject */
	private $manualFaceService;
	/** @var ApiController */
	private $controller;

	public function setUp(): void {
		parent::setUp();

		$request                  = $this->createMock(IRequest::class);
		$this->faceMapper         = $this->createMock(FaceMapper::class);
		$this->imageMapper        = $this->createMock(ImageMapper::class);
		$this->clusterMapper      = $this->createMock(ClusterMapper::class);
		$this->personMapper       = $this->createMock(PersonMapper::class);
		$this->settingsService    = $this->createMock(SettingsService::class);
		$this->urlService         = $this->createMock(UrlService::class);
		$this->manualRegionMapper = $this->createMock(ManualRegionMapper::class);
		$this->logger             = $this->createMock(LoggerInterface::class);
		$this->manualFaceService  = $this->createMock(ManualFaceService::class);

		$this->controller = new ApiController(
			'facerecognition',
			$request,
			$this->faceMapper,
			$this->imageMapper,
			$this->clusterMapper,
			$this->personMapper,
			$this->settingsService,
			$this->urlService,
			$this->manualRegionMapper,
			$this->logger,
			$this->manualFaceService,
			self::USER
		);
	}

	private function enableUser(): void {
		$this->settingsService->method('getUserEnabled')->willReturn(true);
		$this->settingsService->method('getCurrentFaceModel')->willReturn(1);
		$this->settingsService->method('getMinimumFaceSize')->willReturn(40);
		$this->settingsService->method('getMinimumConfidence')->willReturn(0.99);
	}

	/** The database has the columns and the table of this feature. */
	private function migrated(): void {
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->manualRegionMapper->method('isAvailable')->willReturn(true);
	}

	/** The file 42 is one of the user's, and it has the image 10. */
	private function ownFile(): void {
		$file = $this->createMock(File::class);
		$this->urlService->method('getFileNode')->with(42)->willReturn($file);

		$image = new Image();
		$image->setId(10);
		$this->imageMapper->method('findFromFile')->willReturn($image);
	}

	private function makePerson(int $id, string $name): Person {
		$person = new Person();
		$person->setId($id);
		$person->setName($name);
		return $person;
	}

	/** Makes insertManualFace() hand back what it got with an id, and keep it. */
	private function captureInsertedFace(?Face &$inserted): void {
		$this->faceMapper->method('insertManualFace')
			->willReturnCallback(function (Face $face) use (&$inserted) {
				$face->setId(100);
				$inserted = $face;
				return $face;
			});
	}

	// --- addManualFace ----------------------------------------------------

	public function testAddManualFaceRejectsDisabledUser() {
		$this->settingsService->method('getUserEnabled')->willReturn(false);
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000);
		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $resp->getStatus());
	}

	public function testAddManualFaceRejectsInvalidImageDimensions() {
		$this->enableUser();
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 0, 1000);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsOutOfBoundsRectangle() {
		$this->enableUser();
		// x + width = 1.6, which spills outside the right edge of the image.
		$resp = $this->controller->addManualFace(42, 'Alice', 0.8, 0.1, 0.8, 0.2, 1000, 1000);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testAddManualFaceRejectsZeroAreaBox() {
		$this->enableUser();
		// width 0.001 of a 100px image rounds down to 0 pixels -> degenerate face.
		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.001, 0.2, 100, 1000);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertEquals('rectangle too small', $resp->getData()['error']);
	}

	public function testAddManualFaceRejectsInaccessibleFile() {
		$this->enableUser();
		$this->migrated();
		$this->urlService->method('getFileNode')->willReturn(null);
		$this->faceMapper->expects($this->never())->method('insertManualFace');

		$resp = $this->controller->addManualFace(999, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000);
		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	/**
	 * Before the migration ran there is nowhere to keep the state of the
	 * marking, and nothing is written.
	 */
	public function testAddManualFaceIsRefusedBeforeTheMigration() {
		$this->enableUser();
		$this->ownFile();
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->faceMapper->expects($this->never())->method('insertManualFace');
		$this->clusterMapper->expects($this->never())->method('create');

		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000);
		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $resp->getStatus());
	}

	/**
	 * With a name, the face gets a cluster of its own, pointing at the person.
	 */
	public function testAddManualFaceWithANameGetsAClusterOfThatPerson() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->personMapper->expects($this->once())->method('findOrCreateByName')
			->with(self::USER, 'Alice')
			->willReturn($this->makePerson(5, 'Alice'));
		$this->clusterMapper->method('create')->willReturn(7);
		$this->clusterMapper->expects($this->once())->method('setPerson')->with(7, 5);

		$inserted = null;
		$this->captureInsertedFace($inserted);

		$resp = $this->controller->addManualFace(42, 'Alice', 0.1, 0.1, 0.2, 0.2, 1000, 1000);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(100, $data['faceId']);
		$this->assertEquals(7, $data['clusterId']);
		$this->assertEquals(5, $data['personId']);
		$this->assertEquals('Alice', $data['name']);
		$this->assertEquals(Face::MANUAL_STATE_PENDING, $data['manualState']);
		$this->assertEquals(7, $inserted->getCluster());
	}

	/**
	 * Without a name there is no cluster and no person: the clustering places
	 * the face, in the cluster of the person it looks like if there is one.
	 *
	 * @dataProvider noNameProvider
	 */
	public function testAddManualFaceWithoutANameCreatesNoClusterAndNoPerson($personName) {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->personMapper->expects($this->never())->method('findOrCreateByName');
		$this->clusterMapper->expects($this->never())->method('create');
		$this->clusterMapper->expects($this->never())->method('setPerson');

		$inserted = null;
		$this->captureInsertedFace($inserted);

		$resp = $this->controller->addManualFace(42, $personName, 0.1, 0.1, 0.2, 0.2, 1000, 1000);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(100, $data['faceId']);
		$this->assertNull($data['clusterId']);
		$this->assertNull($data['personId']);
		$this->assertNull($data['name']);
		$this->assertNotNull($inserted);
		$this->assertNull($inserted->getCluster());
	}

	public static function noNameProvider(): array {
		return [
			'empty' => [''],
			'blank' => ['   '],
			'missing' => [null],
		];
	}

	/**
	 * A marking waits for the search of its region, and until then it claims
	 * no confidence at all: the value used to be 1.0, which lifted the minimum
	 * confidence for every marking before any detector had looked at it.
	 */
	public function testAddManualFaceWaitsForTheSearchWithoutAnInventedConfidence() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$inserted = null;
		$this->captureInsertedFace($inserted);

		$this->controller->addManualFace(42, '', 0.1, 0.1, 0.2, 0.2, 1000, 1000);

		$this->assertNotNull($inserted);
		$this->assertSame(0.0, (float) $inserted->getConfidence());
		$this->assertTrue((bool) $inserted->getIsManual());
		// Every marking is searched: there is no option to leave it out.
		$this->assertTrue((bool) $inserted->getIsGroupable());
		$this->assertEquals(Face::MANUAL_STATE_PENDING, $inserted->getManualState());
	}

	public function testAddManualFaceStoresPixelsOfTheOriginalImage() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Alice'));
		$this->clusterMapper->method('create')->willReturn(7);

		$inserted = null;
		$this->captureInsertedFace($inserted);

		$this->controller->addManualFace(42, 'Alice', 0.25, 0.5, 0.2, 0.1, 800, 600);

		$this->assertNotNull($inserted);
		$this->assertEquals(200, $inserted->getX());
		$this->assertEquals(300, $inserted->getY());
		$this->assertEquals(160, $inserted->getWidth());
		$this->assertEquals(60, $inserted->getHeight());
	}

	// --- addManualRegion --------------------------------------------------

	/**
	 * A region is queued in pixels of the original photo, and the answer does
	 * not wait for the search.
	 */
	public function testAddManualRegionIsQueued() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->manualRegionMapper->expects($this->once())
			->method('enqueue')
			->with(10, 200, 300, 160, 60)
			->willReturnCallback(function (int $image, int $x, int $y, int $width, int $height) {
				$region = new ManualRegion();
				$region->setId(5);
				$region->setImage($image);
				$region->setState(ManualRegion::STATE_PENDING);
				return $region;
			});

		$resp = $this->controller->addManualRegion(42, 0.25, 0.5, 0.2, 0.1, 800, 600);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(['regionId' => 5, 'state' => ManualRegion::STATE_PENDING], $resp->getData());
	}

	/**
	 * @dataProvider invalidRegionProvider
	 */
	public function testAddManualRegionRejectsRegionsWithoutAreaOrOutsideThePhoto(float $x, float $y, float $width, float $height) {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();
		$this->manualRegionMapper->expects($this->never())->method('enqueue');

		$resp = $this->controller->addManualRegion(42, $x, $y, $width, $height, 800, 600);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public static function invalidRegionProvider(): array {
		return [
			'no width' => [0.1, 0.1, 0.0, 0.2],
			'no height' => [0.1, 0.1, 0.2, 0.0],
			'rounds to nothing' => [0.1, 0.1, 0.0001, 0.2],
			'beyond the right edge' => [0.9, 0.1, 0.2, 0.2],
			'beyond the bottom edge' => [0.1, 0.9, 0.2, 0.2],
			'negative' => [-0.1, 0.1, 0.2, 0.2],
		];
	}

	public function testAddManualRegionRejectsAPhotoOfSomebodyElse() {
		$this->enableUser();
		$this->migrated();
		$this->urlService->method('getFileNode')->willReturn(null);
		$this->manualRegionMapper->expects($this->never())->method('enqueue');
		$this->imageMapper->expects($this->never())->method('insert');

		$resp = $this->controller->addManualRegion(999, 0.1, 0.1, 0.2, 0.2, 800, 600);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testAddManualRegionIsRefusedBeforeTheMigration() {
		$this->enableUser();
		$this->ownFile();
		$this->faceMapper->method('hasManualStateColumn')->willReturn(true);
		$this->manualRegionMapper->method('isAvailable')->willReturn(false);
		$this->manualRegionMapper->expects($this->never())->method('enqueue');

		$resp = $this->controller->addManualRegion(42, 0.1, 0.1, 0.2, 0.2, 800, 600);

		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $resp->getStatus());
	}

	// --- detachFace -------------------------------------------------------

	/** Nothing may be written: not the face, not a cluster, not a person. */
	private function expectNothingDetached(): void {
		$this->clusterMapper->expects($this->never())->method('detachFace');
		$this->personMapper->expects($this->never())->method('findOrCreateByName');
	}

	private function faceOnImage(int $imageId, ?int $clusterId): Face {
		$face = new Face();
		$face->setId(100);
		$face->setImage($imageId);
		$face->setCluster($clusterId);
		return $face;
	}

	/**
	 * The face comes from the request, and it has to be one of the user's: a
	 * face on the image of somebody else is refused, and nothing changes.
	 */
	public function testDetachingAFaceOfAnotherUserIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('find')->with(100)->willReturn($this->faceOnImage(77, 3));
		$this->imageMapper->method('find')->with(self::USER, 77)->willReturn(null);
		$this->expectNothingDetached();

		$resp = $this->controller->detachFace(100, 'Bob');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testDetachingAFaceThatDoesNotExistIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn(null);
		$this->expectNothingDetached();

		$resp = $this->controller->detachFace(404, 'Bob');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testDetachingAFaceWithoutClusterIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn($this->faceOnImage(10, null));
		$this->imageMapper->method('find')->willReturn(new Image());
		$this->expectNothingDetached();

		$resp = $this->controller->detachFace(100, 'Bob');

		$this->assertEquals(Http::STATUS_CONFLICT, $resp->getStatus());
	}

	public function testAnOwnFaceIsDetached() {
		$this->enableUser();
		$this->faceMapper->method('find')->with(100)->willReturn($this->faceOnImage(10, 3));
		$this->imageMapper->method('find')->with(self::USER, 10)->willReturn(new Image());
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Bob'));

		$cluster = new Cluster();
		$cluster->setId(8);
		$this->clusterMapper->expects($this->once())->method('detachFace')->with(3, 100, 5)->willReturn($cluster);

		$resp = $this->controller->detachFace(100, 'Bob');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
	}

	// --- nameFace ---------------------------------------------------------

	/** Nothing may be written: no person, no cluster, no face. */
	private function expectNothingNamed(): void {
		$this->personMapper->expects($this->never())->method('findOrCreateByName');
		$this->clusterMapper->expects($this->never())->method('create');
		$this->faceMapper->expects($this->never())->method('assignClusterIfNone');
	}

	/**
	 * A marking saved without a name gets a cluster of its own for the person
	 * of the name, like a marking saved with one, and is kept by a later
	 * analysis.
	 */
	public function testAFaceWithoutClusterIsNamed() {
		$this->enableUser();
		$this->faceMapper->method('find')->with(100)->willReturn($this->faceOnImage(10, null));
		$this->imageMapper->method('find')->with(self::USER, 10)->willReturn(new Image());
		$this->personMapper->expects($this->once())->method('findOrCreateByName')
			->with(self::USER, 'Alice')->willReturn($this->makePerson(5, 'Alice'));
		$this->clusterMapper->expects($this->once())->method('create')->with(self::USER, 1)->willReturn(7);
		$this->clusterMapper->expects($this->once())->method('setPerson')->with(7, 5);
		$this->faceMapper->expects($this->once())->method('assignClusterIfNone')->with(100, 7)->willReturn(true);
		$this->faceMapper->expects($this->once())->method('markFaceManual')->with(100);

		$resp = $this->controller->nameFace(100, '  Alice ');

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(['faceId' => 100, 'clusterId' => 7, 'personId' => 5, 'name' => 'Alice'], $resp->getData());
	}

	public function testNamingAFaceOfAnotherUserIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('find')->with(100)->willReturn($this->faceOnImage(77, null));
		$this->imageMapper->method('find')->with(self::USER, 77)->willReturn(null);
		$this->expectNothingNamed();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $this->controller->nameFace(100, 'Alice')->getStatus());
	}

	public function testNamingAFaceThatDoesNotExistIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn(null);
		$this->expectNothingNamed();

		$this->assertEquals(Http::STATUS_NOT_FOUND, $this->controller->nameFace(404, 'Alice')->getStatus());
	}

	/** A face in a cluster is renamed through its cluster, not here. */
	public function testNamingAFaceInAClusterIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn($this->faceOnImage(10, 3));
		$this->imageMapper->method('find')->willReturn(new Image());
		$this->expectNothingNamed();

		$this->assertEquals(Http::STATUS_CONFLICT, $this->controller->nameFace(100, 'Alice')->getStatus());
	}

	/** @dataProvider noNameProvider */
	public function testNamingAFaceWithoutANameIsRefused($name) {
		$this->enableUser();
		$this->expectNothingNamed();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $this->controller->nameFace(100, $name)->getStatus());
	}

	/**
	 * The clustering placed the face between reading it and naming it: the
	 * cluster made for the name goes again, and the request says so.
	 */
	public function testAFaceThatWasClusteredMeanwhileIsNotNamed() {
		$this->enableUser();
		$this->faceMapper->method('find')->willReturn($this->faceOnImage(10, null));
		$this->imageMapper->method('find')->willReturn(new Image());
		$this->personMapper->method('findOrCreateByName')->willReturn($this->makePerson(5, 'Alice'));
		$this->clusterMapper->method('create')->willReturn(7);
		$this->faceMapper->method('assignClusterIfNone')->willReturn(false);
		$this->clusterMapper->expects($this->once())->method('removeIfEmpty')->with(7);
		$this->personMapper->expects($this->once())->method('deleteOrphaned')->with(self::USER);
		$this->faceMapper->expects($this->never())->method('markFaceManual');

		$this->assertEquals(Http::STATUS_CONFLICT, $this->controller->nameFace(100, 'Alice')->getStatus());
	}

	public function testNamingIsRefusedForADisabledUser() {
		$this->settingsService->method('getUserEnabled')->willReturn(false);
		$this->expectNothingNamed();

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $this->controller->nameFace(100, 'Alice')->getStatus());
	}

	// --- deleteFaces, ignoreFaces, unignoreFaces -------------------------

	/**
	 * Faces 100 and 101 on the image 10 of the user, 102 on the image 77 of
	 * somebody else, in the given states.
	 */
	private function facesInStates(?string $state100, ?string $state101): void {
		$faces = [
			100 => $this->faceOnImage(10, 3),
			101 => $this->faceOnImage(10, null),
			102 => $this->faceOnImage(77, null),
		];
		$faces[100]->setManualState($state100);
		$faces[101]->setId(101);
		$faces[101]->setManualState($state101);
		$faces[102]->setId(102);
		$this->faceMapper->method('find')->willReturnCallback(function (int $id) use ($faces) {
			return $faces[$id] ?? null;
		});
		$this->imageMapper->method('find')->willReturnCallback(function (string $user, int $imageId) {
			return $imageId === 10 ? new Image() : null;
		});
	}

	/** Markings, and the faces the search of a region found, are deleted. */
	public function testFacesPutThereByHandAreDeleted() {
		$this->enableUser();
		$this->migrated();
		$this->facesInStates(Face::MANUAL_STATE_FOUND, Face::MANUAL_STATE_PENDING);
		$this->manualFaceService->expects($this->once())->method('delete')
			->with(self::USER, $this->callback(function (array $faces): bool {
				return array_map(function (Face $face) { return $face->getId(); }, $faces) === [100, 101];
			}));

		$resp = $this->controller->deleteFaces([100, 101]);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(['faceIds' => [100, 101]], $resp->getData());
	}

	/**
	 * A face of the analysis would come back with the next analysis of the
	 * photo, so it is not deleted, and neither are the others of the request.
	 */
	public function testAFaceOfTheAnalysisIsNotDeleted() {
		$this->enableUser();
		$this->migrated();
		$this->facesInStates(Face::MANUAL_STATE_FOUND, null);
		$this->manualFaceService->expects($this->never())->method('delete');

		$resp = $this->controller->deleteFaces([100, 101]);

		$this->assertEquals(Http::STATUS_CONFLICT, $resp->getStatus());
		$this->assertEquals([101], $resp->getData()['faceIds']);
	}

	/** A marking the analysis confirmed is a face of the analysis now. */
	public function testAConfirmedMarkingIsNotDeleted() {
		$this->enableUser();
		$this->migrated();
		$this->facesInStates(Face::MANUAL_STATE_CONFIRMED, Face::MANUAL_STATE_FOUND);
		$this->manualFaceService->expects($this->never())->method('delete');

		$this->assertEquals(Http::STATUS_CONFLICT, $this->controller->deleteFaces([100, 101])->getStatus());
	}

	public function testDeletingAFaceOfAnotherUserChangesNothing() {
		$this->enableUser();
		$this->migrated();
		$this->facesInStates(Face::MANUAL_STATE_FOUND, Face::MANUAL_STATE_FOUND);
		$this->manualFaceService->expects($this->never())->method('delete');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $this->controller->deleteFaces([100, 102])->getStatus());
	}

	public function testDeletingAFaceThatDoesNotExistChangesNothing() {
		$this->enableUser();
		$this->migrated();
		$this->facesInStates(Face::MANUAL_STATE_FOUND, Face::MANUAL_STATE_FOUND);
		$this->manualFaceService->expects($this->never())->method('delete');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $this->controller->deleteFaces([100, 404])->getStatus());
	}

	public static function badFaceListProvider(): array {
		return [
			'none' => [[]],
			'not a number' => [['abc']],
			'too many' => [range(1, 501)],
		];
	}

	/** @dataProvider badFaceListProvider */
	public function testABadListOfFacesIsRefused(array $faceIds) {
		$this->enableUser();
		$this->migrated();
		$this->facesInStates(Face::MANUAL_STATE_FOUND, Face::MANUAL_STATE_FOUND);
		$this->manualFaceService->expects($this->never())->method('delete');
		$this->manualFaceService->expects($this->never())->method('ignore');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $this->controller->deleteFaces($faceIds)->getStatus());
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $this->controller->ignoreFaces($faceIds)->getStatus());
	}

	public function testDeletingBeforeTheMigrationIsRefused() {
		$this->enableUser();
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->facesInStates(null, null);
		$this->manualFaceService->expects($this->never())->method('delete');

		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller->deleteFaces([100])->getStatus());
	}

	/** Any face of the user can be ignored, one of the analysis as well. */
	public function testFacesAreIgnored() {
		$this->enableUser();
		$this->facesInStates(null, Face::MANUAL_STATE_FOUND);
		$this->manualFaceService->expects($this->once())->method('ignore')
			->with(self::USER, 1, $this->callback(function (array $faces): bool {
				return count($faces) === 2;
			}))
			->willReturn([100, 101]);

		$resp = $this->controller->ignoreFaces([100, 101]);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(['faceIds' => [100, 101]], $resp->getData());
	}

	public function testIgnoringAFaceOfAnotherUserChangesNothing() {
		$this->enableUser();
		$this->facesInStates(null, null);
		$this->manualFaceService->expects($this->never())->method('ignore');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $this->controller->ignoreFaces([102])->getStatus());
	}

	public function testFacesAreNotIgnoredAnyMore() {
		$this->enableUser();
		$this->facesInStates(null, null);
		$this->manualFaceService->expects($this->once())->method('unignore')->willReturn([100]);

		$resp = $this->controller->unignoreFaces([100]);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(['faceIds' => [100]], $resp->getData());
	}

	public function testChangingFacesIsRefusedForADisabledUser() {
		$this->settingsService->method('getUserEnabled')->willReturn(false);
		$this->manualFaceService->expects($this->never())->method($this->anything());

		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $this->controller->deleteFaces([100])->getStatus());
		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $this->controller->ignoreFaces([100])->getStatus());
		$this->assertEquals(Http::STATUS_PRECONDITION_FAILED, $this->controller->unignoreFaces([100])->getStatus());
	}

	// --- deleteRegions ---------------------------------------------------

	/** Region 5 on the image 10 of the user, 6 on the image 77 of somebody else. */
	private function regions(): void {
		$this->manualRegionMapper->method('isAvailable')->willReturn(true);
		$regions = [];
		foreach ([5 => 10, 6 => 77] as $id => $imageId) {
			$region = new ManualRegion();
			$region->setId($id);
			$region->setImage($imageId);
			$regions[$id] = $region;
		}
		$this->manualRegionMapper->method('findById')->willReturnCallback(function (int $id) use ($regions) {
			return $regions[$id] ?? null;
		});
		$this->imageMapper->method('find')->willReturnCallback(function (string $user, int $imageId) {
			return $imageId === 10 ? new Image() : null;
		});
	}

	public function testARegionOfTheUserIsDeleted() {
		$this->enableUser();
		$this->regions();
		$this->manualRegionMapper->expects($this->once())->method('deleteRegions')->with([5]);

		$resp = $this->controller->deleteRegions([5, 5]);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$this->assertEquals(['regionIds' => [5]], $resp->getData());
	}

	public function testDeletingRegionsOfAnotherUserChangesNothing() {
		$this->enableUser();
		$this->regions();
		$this->manualRegionMapper->expects($this->never())->method('deleteRegions');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $this->controller->deleteRegions([5, 6])->getStatus());
		$this->assertEquals(Http::STATUS_NOT_FOUND, $this->controller->deleteRegions([5, 404])->getStatus());
	}

	/** @dataProvider badFaceListProvider */
	public function testABadListOfRegionsIsRefused(array $regionIds) {
		$this->enableUser();
		$this->regions();
		$this->manualRegionMapper->expects($this->never())->method('deleteRegions');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $this->controller->deleteRegions($regionIds)->getStatus());
	}

	public function testDeletingRegionsBeforeTheMigrationIsRefused() {
		$this->enableUser();
		$this->manualRegionMapper->method('isAvailable')->willReturn(false);
		$this->manualRegionMapper->expects($this->never())->method('deleteRegions');

		$this->assertEquals(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller->deleteRegions([5])->getStatus());
	}

	// --- getFacesForFile --------------------------------------------------

	private function makeFace(int $id, ?int $cluster, int $size, float $confidence, ?bool $groupable, bool $manual, ?string $state, bool $boxAdjusted = false): Face {
		$face = new Face();
		$face->setId($id);
		$face->setCluster($cluster);
		$face->setX(10 * $id);
		$face->setY(20 * $id);
		$face->setWidth($size);
		$face->setHeight($size);
		$face->setConfidence($confidence);
		$face->setIsGroupable($groupable);
		$face->setIsManual($manual);
		$face->setManualState($state);
		$face->setBoxAdjusted($boxAdjusted);
		return $face;
	}

	private function makeRegion(int $id, string $state, int $found, int $tooSmall, int $lowConfidence, ?string $error): ManualRegion {
		$region = new ManualRegion();
		$region->setId($id);
		$region->setImage(10);
		$region->setX(100);
		$region->setY(200);
		$region->setWidth(300);
		$region->setHeight(150);
		$region->setState($state);
		$region->setFoundCount($found);
		$region->setTooSmallCount($tooSmall);
		$region->setLowConfidenceCount($lowConfidence);
		$region->setError($error);
		return $region;
	}

	/** @return array<int, array> the faces of the answer, by id */
	private function facesById(array $data): array {
		$faces = [];
		foreach ($data['faces'] as $face) {
			$faces[$face['id']] = $face;
		}
		return $faces;
	}

	/**
	 * One answer with every state of a marking, every reason to be left out
	 * of the clustering, and a face without a group.
	 */
	public function testFacesForFileDescribeStateParticipationAndGroup() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->faceMapper->method('findFromFile')->with(self::USER, 1, 42)->willReturn([
			// Found by the analysis, and taking part.
			$this->makeFace(1, 7, 100, 1.0, true, false, null),
			// A marking waiting for its search, without a group yet.
			$this->makeFace(2, null, 20, 0.0, true, true, Face::MANUAL_STATE_PENDING),
			// A marking whose search found a face, box moved on the way.
			$this->makeFace(3, 8, 100, 1.02, true, true, Face::MANUAL_STATE_FOUND, true),
			// A marking the analysis found by itself later.
			$this->makeFace(4, 7, 100, 1.0, true, true, Face::MANUAL_STATE_CONFIRMED),
			// A marking where the search found no face.
			$this->makeFace(5, 9, 100, 0.0, false, true, Face::MANUAL_STATE_NO_FACE),
			// Found in a region, but too small.
			$this->makeFace(6, 10, 30, 1.02, true, true, Face::MANUAL_STATE_FOUND),
			// Found in a region, but the detector does not trust it.
			$this->makeFace(7, 11, 100, 0.6, true, true, Face::MANUAL_STATE_FOUND),
			// Found by the analysis, and moved to another person by the user.
			$this->makeFace(8, 12, 100, 1.0, false, true, null),
		]);
		$this->faceMapper->method('countFacesInClusters')->willReturn([7 => 3, 8 => 1, 9 => 1, 10 => 1, 11 => 1, 12 => 1]);
		$this->clusterMapper->method('findPersonNames')->willReturn([7 => 'Alice', 8 => null, 9 => 'Bob', 10 => null, 11 => null, 12 => 'Carol']);
		$this->manualRegionMapper->method('findByImage')->willReturn([]);

		$resp = $this->controller->getFacesForFile(42);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(['minFaceSize' => 40, 'minConfidence' => 0.99], $data['limits']);
		$faces = $this->facesById($data);
		$this->assertCount(8, $faces);

		$this->assertEquals(['auto', 'participating', null, null], $this->stateOf($faces[1]));
		$this->assertEquals(['manual', 'pending', null, Face::MANUAL_STATE_PENDING], $this->stateOf($faces[2]));
		$this->assertEquals(['manual', 'participating', null, Face::MANUAL_STATE_FOUND], $this->stateOf($faces[3]));
		$this->assertEquals(['auto', 'participating', null, Face::MANUAL_STATE_CONFIRMED], $this->stateOf($faces[4]));
		$this->assertEquals(['manual', 'excluded', 'no_face', Face::MANUAL_STATE_NO_FACE], $this->stateOf($faces[5]));
		$this->assertEquals(['manual', 'excluded', 'too_small', Face::MANUAL_STATE_FOUND], $this->stateOf($faces[6]));
		$this->assertEquals(['manual', 'excluded', 'low_confidence', Face::MANUAL_STATE_FOUND], $this->stateOf($faces[7]));
		$this->assertEquals(['auto', 'excluded', 'detached', null], $this->stateOf($faces[8]));

		// The group, its size and its name.
		$this->assertEquals(7, $faces[1]['cluster']);
		$this->assertEquals(3, $faces[1]['clusterSize']);
		$this->assertEquals('Alice', $faces[1]['personName']);
		$this->assertNull($faces[3]['personName']);
		$this->assertEquals(1, $faces[3]['clusterSize']);

		// A face without a group says so.
		$this->assertNull($faces[2]['cluster']);
		$this->assertNull($faces[2]['clusterSize']);
		$this->assertNull($faces[2]['personName']);

		$this->assertTrue($faces[3]['boxAdjusted']);
		$this->assertFalse($faces[1]['boxAdjusted']);
		$this->assertEquals(0.6, $faces[7]['confidence']);
		$this->assertTrue($faces[8]['isManual']);
		$this->assertSame(10, $faces[1]['x']);
		$this->assertSame(100, $faces[1]['width']);
	}

	/** @return array [origin, clustering, excludedReason, manualState] */
	private function stateOf(array $face): array {
		return [$face['origin'], $face['clustering'], $face['excludedReason'], $face['manualState']];
	}

	/**
	 * The groups of all the faces are found out with one query for the sizes
	 * and one for the names, however many faces the photo has.
	 *
	 * @dataProvider faceCountProvider
	 */
	public function testFacesForFileAskForTheGroupsOnceWhateverTheNumberOfFaces(int $count) {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$faces = [];
		$sizes = [];
		for ($i = 1; $i <= $count; $i++) {
			$faces[] = $this->makeFace($i, 100 + $i, 100, 1.0, true, false, null);
			$sizes[100 + $i] = 2;
		}
		$this->faceMapper->method('findFromFile')->willReturn($faces);

		$this->faceMapper->expects($this->once())->method('countFacesInClusters')->willReturn($sizes);
		$this->clusterMapper->expects($this->once())->method('findPersonNames')->willReturn([]);
		$this->imageMapper->expects($this->once())->method('findFromFile');
		$this->manualRegionMapper->expects($this->once())->method('findByImage')->willReturn([]);
		// Nothing is asked face by face.
		$this->clusterMapper->expects($this->never())->method('findById');
		$this->clusterMapper->expects($this->never())->method('find');
		$this->clusterMapper->expects($this->never())->method('countClusterFaces');
		$this->personMapper->expects($this->never())->method('find');

		$resp = $this->controller->getFacesForFile(42);

		$this->assertCount($count, $resp->getData()['faces']);
	}

	public static function faceCountProvider(): array {
		return [
			'one face' => [1],
			'many faces' => [25],
		];
	}

	/**
	 * The regions of the photo come along, pending, done and failed alike.
	 */
	public function testFacesForFileListTheRegionsOfThePhoto() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->faceMapper->method('findFromFile')->willReturn([]);
		$this->manualRegionMapper->method('findByImage')->with(10)->willReturn([
			$this->makeRegion(1, ManualRegion::STATE_PENDING, 0, 0, 0, null),
			$this->makeRegion(2, ManualRegion::STATE_DONE, 4, 1, 2, null),
			$this->makeRegion(3, ManualRegion::STATE_FAILED, 0, 0, 0, 'the file 42 is not available'),
		]);

		$data = $this->controller->getFacesForFile(42)->getData();

		$this->assertCount(3, $data['regions']);
		$this->assertEquals([
			'id' => 2, 'x' => 100, 'y' => 200, 'width' => 300, 'height' => 150,
			'state' => 'done', 'foundCount' => 4, 'tooSmallCount' => 1, 'lowConfidenceCount' => 2,
			'error' => null,
		], $data['regions'][1]);
		$this->assertEquals('pending', $data['regions'][0]['state']);
		$this->assertEquals('failed', $data['regions'][2]['state']);
		$this->assertEquals('the file 42 is not available', $data['regions'][2]['error']);
	}

	/**
	 * When the size of the groups cannot be found out, the faces are still
	 * given, with the size unknown, and the failure is logged.
	 */
	/**
	 * A face in a hidden group is one the user ignored: it says so, with
	 * that as the reason it takes no part, and keeps where it came from.
	 */
	public function testAnIgnoredFaceIsReportedIgnored() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->faceMapper->method('findFromFile')->willReturn([
			$this->makeFace(1, 7, 100, 1.0, true, false, null),
			$this->makeFace(2, 13, 100, 1.02, false, true, Face::MANUAL_STATE_FOUND),
		]);
		$this->faceMapper->method('countFacesInClusters')->willReturn([7 => 3, 13 => 1]);
		$this->clusterMapper->method('findPersonNames')->willReturn([7 => 'Alice', 13 => null]);
		$this->clusterMapper->method('findHiddenIds')->with(self::USER, [7, 13])->willReturn([13]);
		$this->manualRegionMapper->method('findByImage')->willReturn([]);

		$faces = $this->facesById($this->controller->getFacesForFile(42)->getData());

		$this->assertFalse($faces[1]['ignored']);
		$this->assertEquals(['auto', 'participating', null, null], $this->stateOf($faces[1]));
		$this->assertTrue($faces[2]['ignored']);
		$this->assertEquals(['manual', 'excluded', 'ignored', Face::MANUAL_STATE_FOUND], $this->stateOf($faces[2]));
	}

	/** When the ignored faces cannot be found out, that is left open. */
	public function testFacesForFileAreGivenEvenIfTheIgnoredOnesAreUnknown() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->faceMapper->method('findFromFile')->willReturn([$this->makeFace(1, 7, 100, 1.0, true, false, null)]);
		$this->faceMapper->method('countFacesInClusters')->willReturn([7 => 3]);
		$this->clusterMapper->method('findPersonNames')->willReturn([7 => 'Alice']);
		$this->clusterMapper->method('findHiddenIds')->willThrowException(new \RuntimeException('database gone'));
		$this->manualRegionMapper->method('findByImage')->willReturn([]);

		$faces = $this->controller->getFacesForFile(42)->getData()['faces'];

		$this->assertNull($faces[0]['ignored']);
		$this->assertEquals('participating', $faces[0]['clustering']);
	}

	public function testFacesForFileAreGivenEvenIfTheGroupSizeFails() {
		$this->enableUser();
		$this->migrated();
		$this->ownFile();

		$this->faceMapper->method('findFromFile')->willReturn([
			$this->makeFace(1, 7, 100, 1.0, true, false, null),
		]);
		$this->faceMapper->method('countFacesInClusters')->willThrowException(new \RuntimeException('database gone'));
		$this->clusterMapper->method('findPersonNames')->willReturn([7 => 'Alice']);
		$this->manualRegionMapper->method('findByImage')->willReturn([]);

		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('[manual faces]'));

		$resp = $this->controller->getFacesForFile(42);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$faces = $resp->getData()['faces'];
		$this->assertCount(1, $faces);
		// In a group, with a size nobody knows.
		$this->assertEquals(7, $faces[0]['cluster']);
		$this->assertNull($faces[0]['clusterSize']);
		$this->assertEquals('Alice', $faces[0]['personName']);
		$this->assertEquals('participating', $faces[0]['clustering']);
	}

	/**
	 * Before the migration ran the faces are given as they were, without the
	 * state, and without regions, which tells the client not to offer them.
	 */
	public function testFacesForFileBeforeTheMigration() {
		$this->enableUser();
		$this->ownFile();
		$this->faceMapper->method('hasManualStateColumn')->willReturn(false);
		$this->manualRegionMapper->method('isAvailable')->willReturn(false);
		$this->manualRegionMapper->expects($this->never())->method('findByImage');

		$this->faceMapper->method('findFromFile')->willReturn([
			$this->makeFace(1, 7, 100, 1.0, true, false, null),
		]);
		$this->faceMapper->method('countFacesInClusters')->willReturn([7 => 1]);
		$this->clusterMapper->method('findPersonNames')->willReturn([7 => 'Alice']);

		$data = $this->controller->getFacesForFile(42)->getData();

		$this->assertNull($data['regions']);
		$this->assertCount(1, $data['faces']);
		$this->assertEquals('Alice', $data['faces'][0]['personName']);
		$this->assertNull($data['faces'][0]['clustering']);
		$this->assertNull($data['faces'][0]['origin']);
		$this->assertNull($data['faces'][0]['manualState']);
	}
}

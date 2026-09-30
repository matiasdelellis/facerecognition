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

use OCP\IRequest;
use OCP\AppFramework\Http;

use Psr\Log\LoggerInterface;

use OCA\FaceRecognition\Controller\ApiController;
use OCA\FaceRecognition\Controller\ClusterController;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\ManualRegionMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\ClusterLinkService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

/**
 * When the analysis of a photo finds a face that the user put there, by
 * marking it, by searching a region or by moving it to another person, its
 * find takes over the row that is there instead of adding a second one. The
 * face keeps its id, its person and its protection from being replaced.
 *
 * @group DB
 */
class AnalysisTakesOverManualFaceTest extends ManualFaceIntegrationTestCase {

	/** @var Image */
	private $image;

	public function setUp(): void {
		parent::setUp();
		$this->image = $this->upload('lenna.jpg', $this->lenna());
	}

	/**
	 * Finds the box the given pass puts on the face of lenna.jpg, and leaves
	 * the photo as if it had never been analyzed.
	 */
	private function boxOfThePass(bool $fastPass): array {
		$imageMapper = $this->container->query(ImageMapper::class);

		$this->analyze($fastPass);
		$faceIds = $this->faceIdsOf($this->image->getId());
		$this->assertCount(1, $faceIds);
		$row = $this->row($faceIds[0]);

		$this->container->query(FaceMapper::class)->removeFromImage($this->image->getId());
		$imageMapper->resetImage($this->image);

		return ['x' => $row['x'], 'y' => $row['y'], 'width' => $row['width'], 'height' => $row['height']];
	}

	/**
	 * The refinement finds the face the search of a region created: there is
	 * one face in that place afterwards, the same row, with what the analysis
	 * found, the name it had, and the state that says the analysis confirmed
	 * it. A later analysis that does not find it any more leaves it there.
	 */
	public function testTheRefinementTakesOverAFaceFoundInARegion() {
		$box = $this->boxOfThePass(false);
		$rescan = $this->insertRescanFace($this->image->getId(), $box, self::descriptor(0.3));
		$clusterId = $this->clusterOf('Anna');
		$this->container->query(ClusterMapper::class)->attachFaces([$rescan->getId()], $clusterId);
		$before = $this->row($rescan->getId());

		$this->analyze(false);

		$this->assertEquals([$rescan->getId()], $this->faceIdsOf($this->image->getId()));
		$row = $this->row($rescan->getId());
		$this->assertEquals(Face::MANUAL_STATE_CONFIRMED, $row['manual_state']);
		$this->assertNotEquals(self::descriptor(0.3), $row['descriptor'], 'The descriptor is the one of the analysis');
		$this->assertNotEquals($before['confidence'], $row['confidence'], 'The confidence is the one of the analysis');
		$this->assertNotEquals($before['landmarks'], $row['landmarks'], 'The landmarks are the ones of the analysis');
		$this->assertTrue($row['is_manual']);
		$this->assertEquals($clusterId, $row['cluster']);
		$this->assertEquals('Anna', $this->nameOfCluster($clusterId));

		// A later analysis that finds nothing does not remove it.
		$imageMapper = $this->container->query(ImageMapper::class);
		$imageMapper->imageProcessed($imageMapper->find($this->user->getUID(), $this->image->getId()), [], 5, null, true);
		$this->assertEquals([$rescan->getId()], $this->faceIdsOf($this->image->getId()));
		$this->assertEquals('Anna', $this->nameOfCluster($this->row($rescan->getId())['cluster']));
	}

	/**
	 * The same holds for a face that has no cluster yet, which is what a find
	 * of a region is until the clustering places it.
	 */
	public function testTheRefinementTakesOverAFaceWithoutCluster() {
		$box = $this->boxOfThePass(false);
		$rescan = $this->insertRescanFace($this->image->getId(), $box, self::descriptor(0.3));

		$this->analyze(false);

		$this->assertEquals([$rescan->getId()], $this->faceIdsOf($this->image->getId()));
		$this->assertEquals(Face::MANUAL_STATE_CONFIRMED, $this->row($rescan->getId())['manual_state']);
		$this->assertNull($this->row($rescan->getId())['cluster']);
	}

	/**
	 * A marking where the search found no face gets back into the clustering
	 * when the analysis finds a face there.
	 */
	public function testAMarkingWithoutFaceGetsBackIntoTheClustering() {
		$box = $this->boxOfThePass(false);
		$marking = $this->insertMarking($this->image->getId(), $box['x'], $box['y'], $box['width'], $box['height']);
		$this->container->query(FaceMapper::class)->markManualFaceNotGroupable($marking->getId());
		$this->setColumns($marking->getId(), ['box_adjusted' => true]);
		$this->assertFalse($this->row($marking->getId())['is_groupable']);

		$this->analyze(false);

		$row = $this->row($marking->getId());
		$this->assertEquals([$marking->getId()], $this->faceIdsOf($this->image->getId()));
		$this->assertEquals(Face::MANUAL_STATE_CONFIRMED, $row['manual_state']);
		$this->assertTrue($row['is_groupable']);
		$this->assertNotEmpty($row['descriptor']);
		// The box is the one of the analysis now, not one moved from a drawing.
		$this->assertFalse((bool) $row['box_adjusted']);
	}

	/**
	 * A face the user moved to another person stays out of the clustering
	 * when the analysis finds it again, keeps its person, and is not a
	 * marking.
	 */
	public function testAMovedFaceStaysOutOfTheClustering() {
		$this->analyze(true);
		$this->runClustering();
		$faceIds = $this->faceIdsOf($this->image->getId());
		$this->assertCount(1, $faceIds);
		$faceId = $faceIds[0];
		$clusterId = $this->row($faceId)['cluster'];

		$resp = $this->clusterController()->updateName($clusterId, 'Bob', $faceId);
		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$moved = $this->row($faceId);
		$this->assertFalse($moved['is_groupable']);
		$this->assertTrue($moved['is_manual']);

		$this->analyze(false);

		$row = $this->row($faceId);
		$this->assertEquals([$faceId], $this->faceIdsOf($this->image->getId()));
		$this->assertFalse($row['is_groupable']);
		$this->assertNull($row['manual_state']);
		$this->assertEquals($moved['cluster'], $row['cluster']);
		$this->assertEquals('Bob', $this->nameOfCluster($row['cluster']));
	}

	/**
	 * The fast pass works on a small image: it drops what it finds in the
	 * place of a face the user put there, and leaves that face as it is. The
	 * refinement then takes it over.
	 */
	public function testTheFastPassDropsTheFindAndTheRefinementTakesOver() {
		$box = $this->boxOfThePass(true);
		$rescan = $this->insertRescanFace($this->image->getId(), $box, self::descriptor(0.3));

		$this->analyze(true);

		$this->assertEquals([$rescan->getId()], $this->faceIdsOf($this->image->getId()));
		$row = $this->row($rescan->getId());
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $row['manual_state']);
		$this->assertEquals(self::descriptor(0.3), $row['descriptor']);
		$this->assertEquals($box, ['x' => $row['x'], 'y' => $row['y'], 'width' => $row['width'], 'height' => $row['height']]);

		$this->analyze(false);

		$this->assertEquals([$rescan->getId()], $this->faceIdsOf($this->image->getId()));
		$row = $this->row($rescan->getId());
		$this->assertEquals(Face::MANUAL_STATE_CONFIRMED, $row['manual_state']);
		$this->assertNotEquals(self::descriptor(0.3), $row['descriptor']);
	}

	/**
	 * A face the user put somewhere the analysis finds nothing stays as it is,
	 * and the faces of the analysis are created next to it.
	 */
	public function testAManualFaceElsewhereIsLeftAlone() {
		$rescan = $this->insertRescanFace($this->image->getId(), ['x' => 0, 'y' => 0, 'width' => 30, 'height' => 30], self::descriptor(0.3));

		$this->analyze(false);

		$faceIds = $this->faceIdsOf($this->image->getId());
		$this->assertCount(2, $faceIds);
		$this->assertContains($rescan->getId(), $faceIds);
		$row = $this->row($rescan->getId());
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $row['manual_state']);
		$this->assertEquals(self::descriptor(0.3), $row['descriptor']);
		$this->assertEquals([0, 0, 30, 30], [$row['x'], $row['y'], $row['width'], $row['height']]);
	}

	/**
	 * A marking the search found, and that the user then took out of its
	 * group, is said to be detached, while its state still says what the
	 * search found: the participation is derived, and cannot disagree with
	 * the clustering.
	 */
	public function testAFoundMarkingTakenOutOfItsGroupIsReportedDetached() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$clusterId = $this->clusterOf('Anna');
		$marking = $this->insertMarking($this->image->getId(), 49, 62, 75, 75, $clusterId);
		$faceMapper->setManualFaceDescriptor($marking->getId(), self::descriptor(0.0), 49, 62, 75, 75, 1.05);

		$faces = $this->facesOfThePhoto();
		$this->assertEquals('participating', $faces[$marking->getId()]['clustering']);

		// Moving it to another person takes it out of the clustering.
		$resp = $this->clusterController()->updateName($clusterId, 'Bob', $marking->getId());
		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());

		$faces = $this->facesOfThePhoto();
		$this->assertEquals('excluded', $faces[$marking->getId()]['clustering']);
		$this->assertEquals('detached', $faces[$marking->getId()]['excludedReason']);
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $faces[$marking->getId()]['manualState']);
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $this->row($marking->getId())['manual_state']);
	}

	/** @return array<int, array> the faces of lenna.jpg as the API gives them, by id */
	private function facesOfThePhoto(): array {
		$data = $this->apiController()->getFacesForFile($this->image->getFile())->getData();
		$faces = [];
		foreach ($data['faces'] as $face) {
			$faces[$face['id']] = $face;
		}
		return $faces;
	}

	private function urlService(): UrlService {
		return new UrlService(
			$this->container->query('OCP\Files\IRootFolder'),
			$this->container->query('OCP\IUserSession'),
			$this->container->query('OCP\IURLGenerator'),
			$this->container->query(SettingsService::class),
			$this->user->getUID());
	}

	private function clusterController(): ClusterController {
		return new ClusterController('facerecognition',
			$this->container->query(IRequest::class),
			$this->container->query(FaceMapper::class),
			$this->container->query(ImageMapper::class),
			$this->container->query(ClusterMapper::class),
			$this->container->query(PersonMapper::class),
			$this->container->query(ClusterLinkService::class),
			$this->container->query(SettingsService::class),
			$this->urlService(),
			$this->user->getUID());
	}

	private function apiController(): ApiController {
		return new ApiController('facerecognition',
			$this->container->query(IRequest::class),
			$this->container->query(FaceMapper::class),
			$this->container->query(ImageMapper::class),
			$this->container->query(ClusterMapper::class),
			$this->container->query(PersonMapper::class),
			$this->container->query(SettingsService::class),
			$this->urlService(),
			$this->container->query(ManualRegionMapper::class),
			$this->container->query(LoggerInterface::class),
			$this->container->query(ManualFaceService::class),
			$this->user->getUID());
	}
}

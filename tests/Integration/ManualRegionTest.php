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

use OCA\FaceRecognition\BackgroundJob\Tasks\ManualRegionTask;

use OCA\FaceRecognition\Controller\ApiController;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\ManualRegion;
use OCA\FaceRecognition\Db\ManualRegionMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Helper\ManualFaceDetector;

use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Service\UrlService;

/**
 * A region of a photo searched again for faces: queued by the API, searched by
 * the background job, and every face of it created.
 *
 * @group DB
 */
class ManualRegionTest extends ManualFaceIntegrationTestCase {

	/** @var ManualRegionMapper */
	private $regionMapper;

	public function setUp(): void {
		parent::setUp();
		$this->regionMapper = $this->container->query(ManualRegionMapper::class);
		$this->assertTrue($this->regionMapper->isAvailable(), 'The table of the regions must exist');
	}

	private function pendingIds(): array {
		return array_map(function (array $row) {
			return (int) $row['id'];
		}, $this->regionMapper->findPending($this->user->getUID(), self::MODEL_ID));
	}

	private function region(int $imageId, int $regionId): ManualRegion {
		foreach ($this->regionMapper->findByImage($imageId) as $region) {
			if ($region->getId() === $regionId) {
				return $region;
			}
		}
		$this->fail('No region ' . $regionId . ' on image ' . $imageId);
	}

	private function controller(): ApiController {
		$uid = $this->user->getUID();
		$settingsService = $this->container->query(SettingsService::class);
		$urlService = new UrlService(
			$this->container->query('OCP\Files\IRootFolder'),
			$this->container->query('OCP\IUserSession'),
			$this->container->query('OCP\IURLGenerator'),
			$settingsService,
			$uid);

		return new ApiController('facerecognition',
			$this->container->query(IRequest::class),
			$this->container->query(FaceMapper::class),
			$this->container->query(ImageMapper::class),
			$this->container->query(ClusterMapper::class),
			$this->container->query(PersonMapper::class),
			$settingsService,
			$urlService,
			$this->regionMapper,
			$this->container->query(LoggerInterface::class),
			$this->container->query(ManualFaceService::class),
			$uid);
	}

	/**
	 * What the mapper does: queue, read the pending ones and the ones of an
	 * image, and record the result.
	 */
	public function testTheMapperKeepsTheRegionsAndTheirResult() {
		$image = $this->upload('lenna.jpg', $this->lenna());

		$first = $this->regionMapper->enqueue($image->getId(), 10, 20, 30, 40);
		$second = $this->regionMapper->enqueue($image->getId(), 50, 60, 70, 80);
		$third = $this->regionMapper->enqueue($image->getId(), 1, 2, 3, 4);

		$pending = $this->regionMapper->findPending($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals([$first->getId(), $second->getId(), $third->getId()], $this->pendingIds());
		$this->assertEquals($image->getFile(), (int) $pending[0]['file']);
		$this->assertEquals([10, 20, 30, 40], [(int) $pending[0]['x'], (int) $pending[0]['y'], (int) $pending[0]['width'], (int) $pending[0]['height']]);

		$this->regionMapper->markDone($first->getId(), 4, 1, 2);
		$this->regionMapper->markFailed($second->getId(), 'the file is gone');

		$this->assertEquals([$third->getId()], $this->pendingIds());

		$done = $this->region($image->getId(), $first->getId());
		$this->assertEquals(ManualRegion::STATE_DONE, $done->getState());
		$this->assertEquals([4, 1, 2], [$done->getFoundCount(), $done->getTooSmallCount(), $done->getLowConfidenceCount()]);
		$this->assertNotNull($done->getProcessedTime());
		$this->assertNull($done->getError());

		$failed = $this->region($image->getId(), $second->getId());
		$this->assertEquals(ManualRegion::STATE_FAILED, $failed->getState());
		$this->assertEquals('the file is gone', $failed->getError());
		$this->assertNotNull($failed->getProcessedTime());

		$this->assertCount(3, $this->regionMapper->findByImage($image->getId()));
	}

	/**
	 * A region taken by the API is queued in pixels of the original photo, and
	 * waits for the background job.
	 */
	public function testARegionOfTheApiIsQueued() {
		$image = $this->upload('lenna.jpg', $this->lenna());

		$resp = $this->controller()->addManualRegion($image->getFile(), 0.25, 0.25, 0.5, 0.5, 158, 158);

		$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertEquals(ManualRegion::STATE_PENDING, $data['state']);
		$this->assertEquals([$data['regionId']], $this->pendingIds());

		$region = $this->region($image->getId(), $data['regionId']);
		$this->assertEquals([40, 40, 79, 79], [$region->getX(), $region->getY(), $region->getWidth(), $region->getHeight()]);

		// And it shows among the regions of the photo.
		$faces = $this->controller()->getFacesForFile($image->getFile())->getData();
		$this->assertEquals($data['regionId'], $faces['regions'][0]['id']);
		$this->assertEquals('pending', $faces['regions'][0]['state']);
	}

	/**
	 * A region of two faces creates both, as found by the detector, with the
	 * confidence the detector gave each of them, and without a cluster.
	 */
	public function testEveryFaceOfARegionIsCreated() {
		$image = $this->upload('two.jpg', $this->twoLennas(2));
		$region = $this->regionMapper->enqueue($image->getId(), 0, 0, 632, 316);

		$this->runRegionTask();

		$faceIds = $this->faceIdsOf($image->getId());
		$this->assertCount(2, $faceIds);

		$rows = array_map([$this, 'row'], $faceIds);
		usort($rows, function (array $a, array $b) {
			return $a['x'] <=> $b['x'];
		});
		// One face on each half of the photo.
		$this->assertLessThan(316, $rows[0]['x'] + $rows[0]['width']);
		$this->assertGreaterThanOrEqual(316, $rows[1]['x']);

		// The same finds as the detector gives for the same crop.
		$detected = $this->detectAgain($image->getFile(), ['x' => 0, 'y' => 0, 'width' => 632, 'height' => 316]);
		foreach ($rows as $index => $row) {
			$this->assertEquals(Face::MANUAL_STATE_FOUND, $row['manual_state']);
			$this->assertTrue($row['is_manual']);
			$this->assertTrue($row['is_groupable']);
			$this->assertNull($row['cluster']);
			$this->assertNotEmpty($row['descriptor']);
			$this->assertNotEmpty($row['landmarks']);
			$this->assertEqualsWithDelta($detected[$index]['confidence'], $row['confidence'], 0.0001);
			$this->assertEquals([$detected[$index]['x'], $detected[$index]['y']], [$row['x'], $row['y']]);
		}

		$done = $this->region($image->getId(), $region->getId());
		$this->assertEquals(ManualRegion::STATE_DONE, $done->getState());
		$this->assertEquals(2, $done->getFoundCount());
	}

	/**
	 * The same region searched twice creates its faces once.
	 */
	public function testTheSameRegionTwiceCreatesNoDuplicates() {
		$image = $this->upload('two.jpg', $this->twoLennas(2));
		$this->regionMapper->enqueue($image->getId(), 0, 0, 632, 316);
		$this->runRegionTask();
		$before = $this->faceIdsOf($image->getId());
		$this->assertCount(2, $before);

		$again = $this->regionMapper->enqueue($image->getId(), 0, 0, 632, 316);
		$this->runRegionTask();

		$this->assertEquals($before, $this->faceIdsOf($image->getId()));
		$done = $this->region($image->getId(), $again->getId());
		$this->assertEquals(ManualRegion::STATE_DONE, $done->getState());
		$this->assertEquals(0, $done->getFoundCount());
	}

	/**
	 * Deleting a photo removes its image and its faces, but not its regions:
	 * the next run deletes those, and leaves the regions of the photos that
	 * are still there alone.
	 */
	public function testTheRegionsOfADeletedPhotoAreDeletedByTheNextRun() {
		$kept = $this->upload('kept.jpg', $this->lenna());
		$gone = $this->upload('gone.jpg', $this->lenna());
		$keptRegion = $this->regionMapper->enqueue($kept->getId(), 0, 0, 158, 158);
		$goneRegion = $this->regionMapper->enqueue($gone->getId(), 0, 0, 158, 158);

		// What PostDeleteListener and StaleImagesRemovalTask do with the image
		// of a photo that is gone; the listener does not run in the tests.
		$this->container->query(FaceMapper::class)->removeFromImage($gone->getId());
		$this->container->query(ImageMapper::class)->delete($gone);
		$this->assertCount(1, $this->regionMapper->findByImage($gone->getId()), 'The region is left behind until the next run');

		$this->runRegionTask();

		$this->assertEquals([], $this->regionMapper->findByImage($gone->getId()));
		$this->assertEquals(ManualRegion::STATE_DONE, $this->region($kept->getId(), $keptRegion->getId())->getState());
		$this->assertNotEquals($keptRegion->getId(), $goneRegion->getId());
	}

	/**
	 * A region that fails records why, and it is not searched again.
	 */
	/**
	 * The user deletes the regions they drew, searched or still waiting, and
	 * the faces a search found stay. A region of somebody else is refused,
	 * and then nothing is deleted.
	 */
	public function testRegionsAreDeletedAndTheirFacesStay() {
		$image = $this->upload('lenna.jpg', $this->lenna());
		$searched = $this->regionMapper->enqueue($image->getId(), 0, 0, 158, 158);
		$this->runRegionTask();
		$faces = $this->faceIdsOf($image->getId());
		$this->assertNotEmpty($faces, 'The search found the face of lenna');
		$waiting = $this->regionMapper->enqueue($image->getId(), 10, 10, 50, 50);

		$imageMapper = $this->container->query(ImageMapper::class);
		$other = new Image();
		$other->setUser('somebody-else');
		$other->setFile(2100000000 + rand(0, 100000));
		$other->setModel(self::MODEL_ID);
		$other->setIsProcessed(false);
		$other = $imageMapper->insert($other);
		$foreign = $this->regionMapper->enqueue($other->getId(), 0, 0, 10, 10);

		try {
			$refused = $this->controller()->deleteRegions([$searched->getId(), $foreign->getId()]);
			$this->assertEquals(Http::STATUS_FORBIDDEN, $refused->getStatus());
			$this->assertCount(2, $this->regionMapper->findByImage($image->getId()));

			$resp = $this->controller()->deleteRegions([$searched->getId(), $waiting->getId()]);
			$this->assertEquals(Http::STATUS_OK, $resp->getStatus());
			$this->assertEquals(['regionIds' => [$searched->getId(), $waiting->getId()]], $resp->getData());
			$this->assertEquals([], $this->regionMapper->findByImage($image->getId()));
			$this->assertEquals([], $this->pendingIds(), 'The waiting one is not searched any more');
			$this->assertEquals($faces, $this->faceIdsOf($image->getId()));
		} finally {
			$this->regionMapper->deleteRegions([$foreign->getId()]);
			$imageMapper->delete($other);
		}
	}

	public function testARegionThatFailsIsNotSearchedAgain() {
		$image = $this->imageOfAMissingFile();
		$region = $this->regionMapper->enqueue($image->getId(), 0, 0, 100, 100);

		$this->runRegionTask();

		$failed = $this->region($image->getId(), $region->getId());
		$this->assertEquals(ManualRegion::STATE_FAILED, $failed->getState());
		$this->assertStringContainsString((string) $image->getFile(), $failed->getError());
		$this->assertEquals([], $this->pendingIds());

		// The next run leaves it alone.
		$this->runRegionTask();
		$this->assertEquals([], $this->pendingIds());
		$this->assertEquals($failed->getProcessedTime(), $this->region($image->getId(), $region->getId())->getProcessedTime());
	}

	/**
	 * What the detector finds in a region, with the margin of a region, from
	 * left to right.
	 */
	private function detectAgain(int $fileId, array $rect): array {
		$model = $this->container->query(ModelManager::class)->getCurrentModel();
		$model->open();
		$detector = new ManualFaceDetector($this->container->query(FileService::class), $this->container->query('OCP\ITempManager'));
		$faces = $detector->detect($model, $this->user->getUID(), $fileId, $rect, ManualRegionTask::REGION_MARGIN, ManualRegionTask::REGION_MARGIN);
		$detector->clean();

		usort($faces, function (array $a, array $b) {
			return $a['x'] <=> $b['x'];
		});
		return $faces;
	}
}

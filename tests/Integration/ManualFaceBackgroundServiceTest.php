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

use OCA\FaceRecognition\AppInfo\Application;
use OCA\FaceRecognition\BackgroundJob\BackgroundService;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\ManualRegion;
use OCA\FaceRecognition\Db\ManualRegionMapper;

/**
 * The background job treats any exception of a task as fatal, and stops the
 * whole run with it. A marking or a region that fails must stay with itself:
 * the other ones are searched, and the tasks after them run.
 *
 * @group DB
 */
class ManualFaceBackgroundServiceTest extends ManualFaceIntegrationTestCase {

	/**
	 * A marking and a region of a file that is gone come first; a marking and
	 * a region of a photo that is there come after them, and the clustering
	 * after all of them, as in the deferred mode.
	 */
	public function testAFailingMarkingAndRegionDoNotStopTheRun() {
		// The admin sets it on a real instance; without it the requirements
		// check stops the whole run before any task of interest.
		$this->setAppValue('analysis_image_area', (string) (1024 * 1024));

		// The photo is analyzed beforehand: otherwise the analysis of the run
		// finds the face first and takes the marking over (see
		// AnalysisTakesOverManualFaceTest), and the search of the marking,
		// which is what this test is about, would have nothing left to do.
		// This way the search runs, finds the face of the analysis, and
		// merges the marking into it (see ManualFaceMergeAndIgnoreTest).
		// It comes before the missing file, whose image the analysis would
		// report as stale, and the run would then remove it.
		$image = $this->upload('big.jpg', $this->lennaScaled(3));
		$this->analyze(false);

		$missing = $this->imageOfAMissingFile();
		$brokenMarking = $this->insertMarking($missing->getId(), 10, 10, 100, 100);
		$regionMapper = $this->container->query(ManualRegionMapper::class);
		$brokenRegion = $regionMapper->enqueue($missing->getId(), 0, 0, 100, 100);

		$marking = $this->insertMarking($image->getId(), 120, 150, 300, 300);
		$region = $regionMapper->enqueue($image->getId(), 0, 0, 474, 474);

		$service = new BackgroundService(new Application(), $this->context);
		$service->execute(0, false, 'defer-mode', $this->user);

		// The broken ones failed, and say so.
		$this->assertEquals(Face::MANUAL_STATE_NO_FACE, $this->row($brokenMarking->getId())['manual_state']);
		$this->assertEquals(ManualRegion::STATE_FAILED, $this->stateOfRegion($missing->getId(), $brokenRegion->getId()));

		// The good ones after them were searched: the marking turned out to be
		// the face the analysis had found, and went into it.
		$this->assertNull($this->row($marking->getId()), 'The marking was searched, and merged into the face of the analysis');
		$faceIds = $this->faceIdsOf($image->getId());
		$this->assertCount(1, $faceIds);
		$this->assertEquals(ManualRegion::STATE_DONE, $this->stateOfRegion($image->getId(), $region->getId()));

		// The clustering ran after them.
		$imageMapper = $this->container->query(ImageMapper::class);
		$this->assertTrue($imageMapper->find($this->user->getUID(), $image->getId())->getIsProcessed());
		$this->assertNotNull($this->row($faceIds[0])['cluster'], 'The clustering must have run after the search');
	}

	private function stateOfRegion(int $imageId, int $regionId): string {
		foreach ($this->container->query(ManualRegionMapper::class)->findByImage($imageId) as $region) {
			if ($region->getId() === $regionId) {
				return $region->getState();
			}
		}
		$this->fail('No region ' . $regionId);
	}
}

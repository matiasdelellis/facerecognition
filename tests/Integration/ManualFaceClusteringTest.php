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

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * A face marked by hand and the clustering: it waits for its search without
 * being taken by the clustering, and once searched it is clustered like any
 * other face, minimums included.
 *
 * @group DB
 */
class ManualFaceClusteringTest extends ManualFaceIntegrationTestCase {

	/** @var FaceMapper */
	private $faceMapper;

	public function setUp(): void {
		parent::setUp();
		$this->faceMapper = $this->container->query(FaceMapper::class);
	}

	private function minimums(): array {
		$settingsService = $this->container->query(SettingsService::class);
		return [$settingsService->getMinimumFaceSize(), $settingsService->getMinimumConfidence()];
	}

	private function nonGroupable(): array {
		[$minSize, $minConfidence] = $this->minimums();
		return $this->faceMapper->findUnassignedNonGroupableFaces($this->user->getUID(), self::MODEL_ID, $minSize, $minConfidence, 1000);
	}

	private function pending(): array {
		return array_map(function (array $row) {
			return (int) $row['id'];
		}, $this->faceMapper->findManualFacesPendingDescriptor($this->user->getUID(), self::MODEL_ID));
	}

	/**
	 * The markings the search has to do are told by their state, and not by a
	 * missing descriptor: one that has a descriptor and is pending is taken,
	 * one that was found is not.
	 */
	public function testPendingMarkingsAreToldByTheirState() {
		$image = $this->upload('lenna.jpg', $this->lenna());

		$pendingWithDescriptor = $this->insertMarking($image->getId(), 10, 10, 50, 50);
		$this->setColumns($pendingWithDescriptor->getId(), ['descriptor' => self::descriptor(0.0)]);

		$found = $this->insertMarking($image->getId(), 80, 10, 50, 50);
		$this->faceMapper->setManualFaceDescriptor($found->getId(), self::descriptor(0.0), 80, 10, 50, 50, 1.0);

		// A marking left non-groupable by an old search is pending all the same.
		$pendingNotGroupable = $this->insertMarking($image->getId(), 10, 80, 50, 50);
		$this->setColumns($pendingNotGroupable->getId(), ['is_groupable' => false]);

		$this->assertEquals([$pendingWithDescriptor->getId(), $pendingNotGroupable->getId()], $this->pending());
	}

	/**
	 * A marking drawn smaller than the minimum size, which waits for its
	 * search, is not taken to be put in a cluster of its own: once in a
	 * cluster it would never be compared with anything.
	 */
	public function testAPendingMarkingBelowTheMinimumSizeIsNotClusteredAlone() {
		[$minSize] = $this->minimums();
		$image = $this->upload('lenna.jpg', $this->lenna());

		$small = $this->insertMarking($image->getId(), 10, 10, $minSize - 10, $minSize - 10);

		$this->assertNotContains($small->getId(), $this->nonGroupable());

		// Once its search is over, it is one of them, like any other face.
		$this->faceMapper->markManualFaceNotGroupable($small->getId());
		$this->assertContains($small->getId(), $this->nonGroupable());
	}

	/**
	 * A face that was measured and is too small, or too uncertain, is still
	 * put in a cluster of its own: there it is on the photo, unnamed, and can
	 * be named by hand.
	 */
	public function testAMeasuredFaceThatFailsTheMinimumsIsStillClusteredAlone() {
		[$minSize, $minConfidence] = $this->minimums();
		$image = $this->upload('lenna.jpg', $this->lenna());

		$tooSmall = $this->insertRescanFace($image->getId(), ['x' => 0, 'y' => 0, 'width' => $minSize - 10, 'height' => $minSize - 10]);
		$uncertain = $this->insertRescanFace($image->getId(), ['x' => 80, 'y' => 80, 'width' => 70, 'height' => 70]);
		$this->setColumns($uncertain->getId(), ['confidence' => $minConfidence / 2]);

		$nonGroupable = $this->nonGroupable();
		$this->assertContains($tooSmall->getId(), $nonGroupable);
		$this->assertContains($uncertain->getId(), $nonGroupable);

		$this->runClustering();

		$clusterMapper = $this->container->query(ClusterMapper::class);
		foreach ([$tooSmall->getId(), $uncertain->getId()] as $faceId) {
			$cluster = $this->row($faceId)['cluster'];
			$this->assertNotNull($cluster);
			$this->assertEquals(1, $clusterMapper->countClusterFaces($cluster));
		}
	}

	/**
	 * The whole of it: a marking without a name, drawn smaller than the
	 * minimum size around a face that is bigger than it, waits through a run
	 * of the clustering, gets its descriptor, and then joins the face it looks
	 * like, instead of hanging in a cluster of its own.
	 */
	public function testASmallUnnamedMarkingEndsInTheClusterOfItsLookalike() {
		// The face of lenna scaled three times is 225 pixels wide, at 147,186.
		$this->setAppValue('min_face_size', '180');
		// What is tested is the size; the confidence of the detector is not.
		$this->setAppValue('min_confidence', '0.5');
		$image = $this->upload('big.jpg', $this->lennaScaled(3));

		// Drawn 150 pixels wide around the middle of the face.
		$marking = $this->insertMarking($image->getId(), 185, 224, 150, 150);

		// The clustering runs before the search, as it does in the default mode.
		$this->runClustering();
		$this->assertNull($this->row($marking->getId())['cluster'], 'A pending marking must not be clustered');

		$this->runDescriptorTask();
		$searched = $this->row($marking->getId());
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $searched['manual_state']);
		$this->assertNotEmpty($searched['descriptor']);
		$this->assertGreaterThanOrEqual(180, $searched['width'], 'The detector found the face bigger than it was drawn');
		$this->assertNull($searched['cluster']);

		// Another photo of the same face.
		$lookalike = $this->insertDetectedFace($searched['descriptor'], 225, 1.0);

		$this->runClustering();

		$cluster = $this->row($marking->getId())['cluster'];
		$this->assertNotNull($cluster);
		$this->assertEquals($cluster, $this->row($lookalike->getId())['cluster']);
		$this->assertEquals(2, $this->container->query(ClusterMapper::class)->countClusterFaces($cluster));
	}

	/**
	 * A marking whose face the detector does not trust enough keeps its
	 * descriptor and its person, but takes no part in the clustering: it is
	 * not a sample of its cluster, and draws nothing into it.
	 */
	public function testAMarkingBelowTheMinimumConfidenceIsNotASampleOfItsCluster() {
		$image = $this->upload('big.jpg', $this->lennaScaled(3));
		$clusterId = $this->clusterOf('Anna');
		$marking = $this->insertMarking($image->getId(), 120, 150, 300, 300, $clusterId);

		$this->runDescriptorTask();
		$searched = $this->row($marking->getId());
		$this->assertEquals(Face::MANUAL_STATE_FOUND, $searched['manual_state']);
		$this->assertNotEmpty($searched['descriptor']);

		// The minimum confidence just above what the detector gave this face.
		$confidence = $searched['confidence'];
		if ($confidence + 0.01 > (float) SettingsService::MAXIMUM_MINIMUM_CONFIDENCE) {
			$this->markTestSkipped('The detector trusts this face more than any minimum confidence can require');
		}
		$this->setAppValue('min_confidence', (string) ($confidence + 0.01));

		[$minSize, $minConfidence] = $this->minimums();
		[$samples] = $this->faceMapper->findClusterSamples($this->user->getUID(), self::MODEL_ID, $minSize, $minConfidence, 10);
		$this->assertArrayNotHasKey($marking->getId(), $samples);

		// The same face on another photo, trusted by the detector, does not
		// join the person through the marking.
		$lookalike = $this->insertDetectedFace($searched['descriptor'], 225, (float) SettingsService::MAXIMUM_MINIMUM_CONFIDENCE);
		$this->runClustering();

		$this->assertNotEquals($clusterId, $this->row($lookalike->getId())['cluster']);

		// The marking is still there, with its descriptor and its person.
		$after = $this->row($marking->getId());
		$this->assertEquals($clusterId, $after['cluster']);
		$this->assertEquals($searched['descriptor'], $after['descriptor']);
		$this->assertEquals('Anna', $this->nameOfCluster($clusterId));
	}
}

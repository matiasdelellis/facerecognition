<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2019, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
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

use OC;

use OCP\IConfig;
use OCP\IUser;
use OCP\AppFramework\App;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Model\ModelManager;

use Test\TestCase;

/**
 * @group DB
 */
class CreateClustersTaskTest extends IntegrationTestCase {

	/** @var int Model used through the tests */
	const MODEL_ID = ModelManager::DEFAULT_FACE_MODEL_ID;

	/**
	 * A face that has no cluster gets one, without waiting for the rest of the
	 * library to be analyzed.
	 */
	public function testCreateSingleFaceCluster() {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');

		$this->insertFace(1, $this->descriptor(0.0));

		$this->doCreateClustersTask();

		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($clusters));

		$faces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertEquals($clusters[0]->getId(), $faces[0]->getCluster());
	}

	/**
	 * Similar faces end up together, and faces that are not similar do not.
	 */
	public function testSimilarFacesAreClusteredTogether() {
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');

		$this->insertFace(1, $this->descriptor(0.0));
		$this->insertFace(2, $this->descriptor(0.01));
		$this->insertFace(3, $this->descriptor(0.02));
		$this->insertFace(4, $this->descriptor(1.0));

		$this->doCreateClustersTask();

		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(2, count($clusters));

		$sizes = [];
		foreach ($clusters as $cluster) {
			$sizes[] = $clusterMapper->countClusterFaces($cluster->getId());
		}
		sort($sizes);
		$this->assertEquals([1, 3], $sizes);
	}

	/**
	 * The faces that are already in a cluster stay where they are when new ones
	 * arrive, the cluster keeps its ID, and therefore keeps its name.
	 */
	public function testClustersSurviveNewFaces() {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');

		$this->insertFace(1, $this->descriptor(0.0));
		$this->insertFace(2, $this->descriptor(0.01));

		$this->doCreateClustersTask();

		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($clusters));
		$clusterId = $clusters[0]->getId();

		// The user says who it is, which is what must not get lost
		$personMapper = $this->container->query('OCA\FaceRecognition\Db\PersonMapper');
		$person = $personMapper->findOrCreateByName($this->user->getUID(), 'Bilbo');
		$clusterMapper->setPerson($clusterId, $person->getId());

		$assignmentBefore = $this->assignment();

		// More faces of the same person, and one of somebody else
		$this->insertFace(3, $this->descriptor(0.02));
		$this->insertFace(4, $this->descriptor(1.0));

		$this->doCreateClustersTask();

		// Nothing that had a cluster moved
		$assignmentAfter = $this->assignment();
		foreach ($assignmentBefore as $faceId => $cluster) {
			$this->assertEquals($cluster, $assignmentAfter[$faceId]);
		}

		// The cluster is still there, still of that person, and it grew
		$cluster = $clusterMapper->find($this->user->getUID(), $clusterId);
		$this->assertEquals($person->getId(), $cluster->getPerson());
		$this->assertEquals('Bilbo', $personMapper->find($this->user->getUID(), $cluster->getPerson())->getName());
		$this->assertEquals(3, $clusterMapper->countClusterFaces($clusterId));

		// And the face of somebody else is in a cluster of its own
		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(2, count($clusters));
	}

	/**
	 * Two clusters of the same person, which is what happens when the faces
	 * arrive in separate runs, are put together as soon as a face that belongs
	 * to both shows up. The bigger one survives.
	 */
	public function testClustersAreMergedByABridgingFace() {
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');

		// Two faces far enough apart to be two clusters
		$this->insertFace(1, $this->descriptor(0.0));
		$this->insertFace(2, $this->descriptor(0.0));
		$this->insertFace(3, $this->descriptor(0.05));

		$this->doCreateClustersTask();

		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(2, count($clusters));

		$biggest = null;
		foreach ($clusters as $cluster) {
			if ($clusterMapper->countClusterFaces($cluster->getId()) === 2) {
				$biggest = $cluster->getId();
			}
		}
		$this->assertNotNull($biggest);

		// A face in between, close enough to both
		$this->insertFace(4, $this->descriptor(0.025));

		$this->doCreateClustersTask();

		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($clusters));
		$this->assertEquals($biggest, $clusters[0]->getId());
		$this->assertEquals(4, $clusterMapper->countClusterFaces($biggest));
	}

	/**
	 * Deleting the faces of one cluster leaves the others alone, and the empty
	 * cluster is removed.
	 */
	public function testDeletingFacesOnlyAffectsItsCluster() {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');

		$imageA = $this->insertImage(1);
		$faceMapper->insertFace(Face::fromModel($imageA, $this->faceFromModel($this->descriptor(0.0))));
		$faceMapper->insertFace(Face::fromModel($imageA, $this->faceFromModel($this->descriptor(0.01))));
		$this->insertFace(2, $this->descriptor(1.0));

		$this->doCreateClustersTask();
		$this->assertEquals(2, count($clusterMapper->findAll($this->user->getUID(), self::MODEL_ID)));

		$survivor = null;
		foreach ($clusterMapper->findAll($this->user->getUID(), self::MODEL_ID) as $cluster) {
			if ($clusterMapper->countClusterFaces($cluster->getId()) === 1) {
				$survivor = $cluster->getId();
			}
		}
		$assignmentBefore = $this->assignment();

		// Everything of the first image goes away
		$faceMapper->removeFromImage($imageA);

		$this->doCreateClustersTask();

		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($clusters));
		$this->assertEquals($survivor, $clusters[0]->getId());

		$assignmentAfter = $this->assignment();
		$this->assertEquals($assignmentBefore[array_key_last($assignmentBefore)],
		                    $assignmentAfter[array_key_last($assignmentAfter)]);
	}

	/**
	 * A face that cannot be grouped, because it is smaller than the minimum
	 * size, gets a cluster of its own and is not compared with anything.
	 */
	public function testNonGroupableFaceGetsItsOwnCluster() {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');

		$tooSmall = $this->faceFromModel($this->descriptor(0.0));
		$tooSmall['right'] = $tooSmall['bottom'] = intdiv($settingsService->getMinimumFaceSize(), 2);
		$faceMapper->insertFace(Face::fromModel($this->insertImage(1), $tooSmall));

		$this->insertFace(2, $this->descriptor(0.0));

		$this->doCreateClustersTask();

		// Same descriptor, but the one that cannot be grouped is left aside
		$this->assertEquals(2, count($clusterMapper->findAll($this->user->getUID(), self::MODEL_ID)));
	}

	/**
	 * A descriptor whose distance to descriptor(0.0) is proportional to $offset,
	 * so that the fixtures can be written in terms of "close" and "far".
	 */
	private function descriptor(float $offset): array {
		$descriptor = [];
		for ($i = 0; $i < 128; $i++) {
			$descriptor[] = $offset;
		}
		return $descriptor;
	}

	private function faceFromModel(array $descriptor): array {
		return [
			"left" => 0, "right" => 100, "top" => 0, "bottom" => 100,
			"detection_confidence" => 1.0,
			"descriptor" => $descriptor,
		];
	}

	private function insertImage(int $fileId): int {
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');

		$image = new Image();
		$image->setUser($this->user->getUid());
		$image->setFile($fileId);
		$image->setModel(self::MODEL_ID);
		$imageMapper->insert($image);

		return $image->getId();
	}

	private function insertFace(int $fileId, array $descriptor): void {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$faceMapper->insertFace(Face::fromModel($this->insertImage($fileId), $this->faceFromModel($descriptor)));
	}

	/** @return array [faceId => clusterId] */
	private function assignment(): array {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		$assignment = [];
		foreach ($faceMapper->getFaces($this->user->getUID(), self::MODEL_ID) as $face) {
			$assignment[$face->getId()] = $face->getCluster();
		}
		ksort($assignment);

		return $assignment;
	}

	/**
	 * Helper method to set up and do create clusters task
	 */
	private function doCreateClustersTask() {
		$clusterMapper = $this->container->query('OCA\FaceRecognition\Db\ClusterMapper');
		$personMapper = $this->container->query('OCA\FaceRecognition\Db\PersonMapper');
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');

		$createClustersTask = new CreateClustersTask($clusterMapper, $personMapper, $imageMapper, $faceMapper, $settingsService);
		$this->assertNotEquals("", $createClustersTask->description());

		$this->context->user = $this->user;

		// Since this task returns generator, iterate until it is done
		$generator = $createClustersTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}
}

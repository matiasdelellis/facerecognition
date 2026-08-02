<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
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
namespace OCA\FaceRecognition\Tests\Integration;

use OC;
use OC\Files\View;

use OCP\IUser;
use OCP\AppFramework\App;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model;
use OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel;
use OCA\FaceRecognition\Model\ModelManager;
use OCA\FaceRecognition\Service\FaceManagementService;
use OCA\FaceRecognition\Service\SettingsService;

use Test\TestCase;

/**
 * The analysis happens in two passes: a fast one with the HOG model on a small
 * image, and a refinement with the current model at maximum quality that
 * replaces the faces of each image. The faces found again in the same place
 * keep their cluster, so a person is never lost.
 *
 * @group DB
 */
class TwoPassProcessingTest extends IntegrationTestCase {

	/** @var int Model used through the tests */
	const MODEL_ID = ModelManager::DEFAULT_FACE_MODEL_ID;

	public function setUp(): void {
		parent::setUp();

		// Small images, so that the tests are quick
		$this->config->setAppValue('facerecognition', 'min_image_size', 1);
		$this->config->setAppValue('facerecognition', 'max_image_area', 200 * 200);

		// Install the models needed: HOG for the fast pass, CNN for the refinement.
		$modelManager = $this->container->query(ModelManager::class);
		$modelManager->getModel(DlibHogModel::FACE_MODEL_ID)->install();
		$modelManager->getModel(DlibCnn5Model::FACE_MODEL_ID)->install();

		// The current model is what the refinement pass uses.
		$settingsService = $this->container->query(SettingsService::class);
		$settingsService->setCurrentFaceModel(self::MODEL_ID);
	}

	/**
	 * A face that was found in the fast pass and clustered keeps its cluster,
	 * and therefore its person, when the image is re-processed in the
	 * refinement pass.
	 */
	public function testRefinementKeepsTheClusterOfTheFace() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$clusterMapper = $this->container->query(ClusterMapper::class);
		$personMapper = $this->container->query(PersonMapper::class);

		// Fast pass: HOG on a small image, and cluster the found face.
		$image = $this->doImageProcessing(true);
		$this->assertFalse($image->getIsRefined());
		$faces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($faces));

		$this->doCreateClustersTask();
		$clusters = $clusterMapper->findAll($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($clusters));
		$clusterId = $clusters[0]->getId();

		// The user says who it is, which is what must not get lost.
		$person = $personMapper->findOrCreateByName($this->user->getUID(), 'Bilbo');
		$clusterMapper->setPerson($clusterId, $person->getId());

		// Refinement pass: the same image is re-processed at maximum quality.
		$image = $this->doImageProcessing(false);
		$this->assertTrue($image->getIsRefined());

		// The new face kept the cluster of the old one.
		$faces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertEquals($clusterId, $faces[0]->getCluster());

		// And the cluster is still of that person.
		$cluster = $clusterMapper->find($this->user->getUID(), $clusterId);
		$this->assertEquals($person->getId(), $cluster->getPerson());
	}

	/**
	 * Images that were analyzed in the fast pass are not picked up again unless
	 * the refinement is what is being done.
	 */
	public function testImagesNotRefinedAreListedForProcessing() {
		$imageMapper = $this->container->query(ImageMapper::class);

		$this->doImageProcessing(true);
		$this->assertEquals(0, count($imageMapper->findImagesWithoutFaces($this->user, self::MODEL_ID)));
		$this->assertEquals(1, count($imageMapper->findImagesToProcess($this->user, self::MODEL_ID)));
	}

	/**
	 * Resetting the refinement makes the refined images due again, so the
	 * refinement can be run once more, e.g. after increasing the size of the
	 * images that the analysis works on.
	 */
	public function testResetRefinedListsTheImagesAgain() {
		$imageMapper = $this->container->query(ImageMapper::class);

		$this->doImageProcessing(true);
		$this->doImageProcessing(false);
		$this->assertEquals(0, count($imageMapper->findImagesToProcess($this->user, self::MODEL_ID)));

		$userManager = $this->container->query('OCP\IUserManager');
		$faceMapper = $this->container->query(FaceMapper::class);
		$clusterMapper = $this->container->query(ClusterMapper::class);
		$personMapper = $this->container->query(PersonMapper::class);
		$settingsService = $this->container->query(SettingsService::class);
		$faceMgmtService = new FaceManagementService($userManager, $faceMapper, $imageMapper, $clusterMapper, $personMapper, $settingsService);
		$faceMgmtService->resetRefined($this->user);

		$this->assertEquals(1, count($imageMapper->findImagesToProcess($this->user, self::MODEL_ID)));
	}

	/**
	 * A face that the user detached keeps its solo cluster and stays
	 * non-groupable through the refinement: it is not put back into the cluster
	 * the user took it out of, and its cluster is not lost.
	 */
	public function testDetachedFaceIsNotRegroupedByTheRefinement() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$clusterMapper = $this->container->query(ClusterMapper::class);

		// Fast pass and cluster the found face.
		$this->doImageProcessing(true);
		$this->doCreateClustersTask();

		$faces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($faces));
		$clusterId = $faces[0]->getCluster();

		// The user detaches the face from its cluster.
		$clusterMapper->detachFace($clusterId, $faces[0]->getId());

		// The refinement replaces the face, keeping the cluster and the
		// non-groupable state.
		$this->doImageProcessing(false);

		$faces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertEquals($clusterId, $faces[0]->getCluster());
		$this->assertFalse($faces[0]->getIsGroupable());
	}

	/**
	 * A failure of the refinement does not destroy what the fast pass found:
	 * the faces stay, so the person the user named is not lost because an
	 * image could not be analyzed again.
	 */
	public function testFailedRefinementKeepsTheFastPassFaces() {
		$imageMapper = $this->container->query(ImageMapper::class);
		$faceMapper = $this->container->query(FaceMapper::class);

		$this->doImageProcessing(true);
		$faces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($faces));

		// The refinement of that image fails.
		$images = $imageMapper->findImages($this->user->getUID(), self::MODEL_ID);
		$image = $imageMapper->find($this->user->getUID(), $images[0]->getId());
		$imageMapper->imageProcessed($image, array(), 0, new \RuntimeException('boom'), true);

		// The faces of the fast pass are still there, and the image did not
		// end up marked as refined.
		$this->assertEquals(1, count($faceMapper->getFaces($this->user->getUID(), self::MODEL_ID)));
		$image = $imageMapper->find($this->user->getUID(), $images[0]->getId());
		$this->assertFalse($image->getIsRefined());
	}

	/**
	 * An image that failed is not taken again on every run: a file that can
	 * never be analyzed would cost every run for as long as it exists. It waits
	 * for an explicit reset of the errors.
	 */
	public function testFailedImageIsNotQueuedAgainUntilTheErrorsAreReset() {
		$imageMapper = $this->container->query(ImageMapper::class);

		$this->doImageProcessing(true);

		$images = $imageMapper->findImages($this->user->getUID(), self::MODEL_ID);
		$image = $imageMapper->find($this->user->getUID(), $images[0]->getId());
		$imageMapper->imageProcessed($image, array(), 0, new \RuntimeException('boom'), true);

		$this->assertEquals(0, count($imageMapper->findImagesToProcess($this->user, self::MODEL_ID)));

		$imageMapper->resetErrors($this->user->getUID());
		$this->assertEquals(1, count($imageMapper->findImagesToProcess($this->user, self::MODEL_ID)));
	}

	/**
	 * Helper method to set up and do image processing, in the given pass.
	 *
	 * @param bool $fastPass Whether to run the fast pass or the refinement
	 *
	 * @return Image The processed image
	 */
	private function doImageProcessing(bool $fastPass) {
		$imageMapper = $this->container->query(ImageMapper::class);
		$faceMapper = $this->container->query(FaceMapper::class);
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query(SettingsService::class);
		$modelManager = $this->container->query(ModelManager::class);
		$lockingProvider = $this->container->query('OCP\Lock\ILockingProvider');
		$imageProcessingTask = new ImageProcessingTask($imageMapper, $faceMapper, $fileService, $settingsService, $modelManager, $lockingProvider);
		$this->assertNotEquals("", $imageProcessingTask->description());

		// Upload the image, if it is not there yet.
		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		if (!$view->file_exists("foo1.jpg")) {
			$imgData = file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg');
			$view->file_put_contents("foo1.jpg", $imgData);
			$this->doMissingImageScan($this->user);
		}

		$this->context->user = $this->user;
		$this->context->propertyBag['run_mode'] = $fastPass ? 'fast-mode' : 'default-mode';

		if (!$fastPass) {
			// The refinement takes the images that the fast pass left behind.
			$enumerateTask = new EnumerateImagesMissingFacesTask($settingsService, $imageMapper);
			$generator = $enumerateTask->execute($this->context);
			foreach ($generator as $_) {
			}
		} else {
			$this->context->propertyBag['images'] = $imageMapper->findImagesWithoutFaces($this->user, self::MODEL_ID);
		}

		$generator = $imageProcessingTask->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());

		$images = $imageMapper->findImages($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($images));
		return $imageMapper->find($this->user->getUID(), $images[0]->getId());
	}

	/**
	 * Helper method to set up and do scanning
	 */
	private function doMissingImageScan($contextUser = null) {
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');

		$imageMapper = $this->container->query(ImageMapper::class);
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query(SettingsService::class);
		$addMissingImagesTask = new AddMissingImagesTask($imageMapper, $fileService, $settingsService);

		$this->context->user = $contextUser;

		$generator = $addMissingImagesTask->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());
	}

	/**
	 * Helper method to set up and do create clusters task
	 */
	private function doCreateClustersTask() {
		$clusterMapper = $this->container->query(ClusterMapper::class);
		$personMapper = $this->container->query(PersonMapper::class);
		$imageMapper = $this->container->query(ImageMapper::class);
		$faceMapper = $this->container->query(FaceMapper::class);
		$settingsService = $this->container->query(SettingsService::class);

		$createClustersTask = new CreateClustersTask($clusterMapper, $personMapper, $imageMapper, $faceMapper, $settingsService);

		$this->context->user = $this->user;

		$generator = $createClustersTask->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());
	}
}

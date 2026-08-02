<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
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
use OC\Files\View;

use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model;
use OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel;
use OCA\FaceRecognition\Model\ModelManager;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * A photo that is shared keeps the file id of its owner in every account, so
 * the same file appears in the table of several users with the same file id.
 * The analysis of the first user can then be reused by the others, instead of
 * running the model again for each of them.
 *
 * @group DB
 */
class ReuseAnalysisTest extends IntegrationTestCase {

	/** @var int Model used through the tests */
	const MODEL_ID = ModelManager::DEFAULT_FACE_MODEL_ID;

	/** @var IUser|null Second user of the test, that reuses the analysis */
	private $otherUser;

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

	public function tearDown(): void {
		if (!is_null($this->otherUser)) {
			$faceMgmtService = $this->container->query('OCA\FaceRecognition\Service\FaceManagementService');
			$faceMgmtService->resetAllForUser($this->otherUser->getUID());
			$this->otherUser->delete();
			$this->otherUser = null;
		}
		parent::tearDown();
	}

	/**
	 * The analysis of a refined image of another user is reused: the faces are
	 * copied, and the image is done even in the fast pass, which would leave
	 * any image it analyzes unrefined.
	 */
	public function testReusesTheRefinedAnalysisOfAnotherUser() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$imageMapper = $this->container->query(ImageMapper::class);

		// The owner analyzes the photo completely (fast pass + refinement).
		$this->analyzeOwnerFastPass();
		$ownerImage = $this->refineOwner();
		$this->assertTrue($ownerImage->getIsRefined());

		// The same file, shared into the other user's account, has the same file id.
		$otherUser = $this->createOtherUser();
		$otherImage = $this->addImageForUser($otherUser, $ownerImage->getFile());

		// A fast pass for that user reuses the refined analysis, instead of running the model.
		$this->runImageProcessing([$otherImage], 'fast-mode', $otherUser->getUID());

		$otherImage = $imageMapper->find($otherUser->getUID(), $otherImage->getId());
		$this->assertTrue($otherImage->getIsProcessed());
		$this->assertTrue($otherImage->getIsRefined());

		// The faces are the same of the owner, but they belong to the other
		// user: unassigned to a cluster, so the clustering stays per-user.
		$ownerFaces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$otherFaces = $faceMapper->getFaces($otherUser->getUID(), self::MODEL_ID);
		$this->assertEquals(count($ownerFaces), count($otherFaces));
		$this->assertNull($otherFaces[0]->getCluster());
		$this->assertEquals($ownerFaces[0]->getDescriptor(), $otherFaces[0]->getDescriptor());
	}

	/**
	 * The fast pass also reuses the fast-pass result of another user, when that
	 * is all there is: the image is processed, but stays pending for the
	 * refinement.
	 */
	public function testFastPassReusesAFastPassResult() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$imageMapper = $this->container->query(ImageMapper::class);

		$ownerImage = $this->analyzeOwnerFastPass();
		$this->assertFalse($ownerImage->getIsRefined());

		$otherUser = $this->createOtherUser();
		$otherImage = $this->addImageForUser($otherUser, $ownerImage->getFile());
		$this->runImageProcessing([$otherImage], 'fast-mode', $otherUser->getUID());

		$otherImage = $imageMapper->find($otherUser->getUID(), $otherImage->getId());
		$this->assertTrue($otherImage->getIsProcessed());
		$this->assertFalse($otherImage->getIsRefined());

		$ownerFaces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$otherFaces = $faceMapper->getFaces($otherUser->getUID(), self::MODEL_ID);
		$this->assertEquals(count($ownerFaces), count($otherFaces));
		$this->assertEquals($ownerFaces[0]->getDescriptor(), $otherFaces[0]->getDescriptor());
	}

	/**
	 * The refinement pass reuses the refined analysis of another user: the
	 * image does not have to be analyzed again at maximum quality.
	 */
	public function testRefinementPassReusesARefinedAnalysis() {
		$faceMapper = $this->container->query(FaceMapper::class);
		$imageMapper = $this->container->query(ImageMapper::class);

		$this->analyzeOwnerFastPass();
		$ownerImage = $this->refineOwner();

		$otherUser = $this->createOtherUser();
		$otherImage = $this->addImageForUser($otherUser, $ownerImage->getFile());
		$this->runImageProcessing([$otherImage], 'default-mode', $otherUser->getUID());

		$otherImage = $imageMapper->find($otherUser->getUID(), $otherImage->getId());
		$this->assertTrue($otherImage->getIsProcessed());
		$this->assertTrue($otherImage->getIsRefined());

		$ownerFaces = $faceMapper->getFaces($this->user->getUID(), self::MODEL_ID);
		$otherFaces = $faceMapper->getFaces($otherUser->getUID(), self::MODEL_ID);
		$this->assertEquals($ownerFaces[0]->getDescriptor(), $otherFaces[0]->getDescriptor());
	}

	/**
	 * The refinement pass does not take the fast-pass result of another user:
	 * reusing it would mark the image done while it is still pending, and it
	 * would be taken again on every refinement run for ever.
	 */
	public function testRefinementPassDoesNotReuseAFastPassResult() {
		$imageMapper = $this->container->query(ImageMapper::class);

		$ownerImage = $this->analyzeOwnerFastPass();
		$this->assertFalse($ownerImage->getIsRefined());

		$otherUser = $this->createOtherUser();
		$otherImage = $this->addImageForUser($otherUser, $ownerImage->getFile());

		// The refinement refuses the reuse. The file does not exist for this
		// user, so the image is not processed at all: the only way it would be
		// marked processed is the reuse that was refused.
		$this->runImageProcessing([$otherImage], 'default-mode', $otherUser->getUID());
		$otherImage = $imageMapper->find($otherUser->getUID(), $otherImage->getId());
		$this->assertFalse($otherImage->getIsProcessed());
		$this->assertFalse($otherImage->getIsRefined());

		// The same image is reused by the fast pass, so it is the refinement
		// pass that refused, and not the duplicate lookup.
		$otherImage->setUser($otherUser->getUID());
		$otherImage->setModel(self::MODEL_ID);
		$this->runImageProcessing([$otherImage], 'fast-mode', $otherUser->getUID());
		$otherImage = $imageMapper->find($otherUser->getUID(), $otherImage->getId());
		$this->assertTrue($otherImage->getIsProcessed());
		$this->assertFalse($otherImage->getIsRefined());
	}

	/**
	 * Helper method to upload the image for the owner and analyze it in the
	 * fast pass.
	 *
	 * @return Image The processed image
	 */
	private function analyzeOwnerFastPass(): Image {
		$imageMapper = $this->container->query(ImageMapper::class);

		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		if (!$view->file_exists("foo1.jpg")) {
			$imgData = file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg');
			$view->file_put_contents("foo1.jpg", $imgData);
			$this->doMissingImageScan($this->user->getUID());
		}

		$images = $imageMapper->findImagesWithoutFaces($this->user, self::MODEL_ID);
		$this->assertEquals(1, count($images));
		$this->runImageProcessing($images, 'fast-mode', $this->user->getUID());

		$images = $imageMapper->findImages($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($images));
		return $imageMapper->find($this->user->getUID(), $images[0]->getId());
	}

	/**
	 * Helper method to refine the images of the owner.
	 *
	 * @return Image The refined image
	 */
	private function refineOwner(): Image {
		$imageMapper = $this->container->query(ImageMapper::class);
		$settingsService = $this->container->query(SettingsService::class);
		$enumerateTask = new EnumerateImagesMissingFacesTask($settingsService, $imageMapper);

		$this->context->user = $this->user;
		$this->context->propertyBag['run_mode'] = 'default-mode';
		$generator = $enumerateTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$images = $this->context->propertyBag['images'];
		$this->assertEquals(1, count($images));
		$this->runImageProcessing($images, 'default-mode', $this->user->getUID());

		$images = $imageMapper->findImages($this->user->getUID(), self::MODEL_ID);
		$this->assertEquals(1, count($images));
		return $imageMapper->find($this->user->getUID(), $images[0]->getId());
	}

	/**
	 * Helper method to run the image processing task on the given images.
	 *
	 * @param Image[] $images Images to process
	 * @param string $runMode The run mode of the pass
	 */
	private function runImageProcessing(array $images, string $runMode, string $userId): void {
		$imageMapper = $this->container->query(ImageMapper::class);
		$faceMapper = $this->container->query(FaceMapper::class);
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query(SettingsService::class);
		$modelManager = $this->container->query(ModelManager::class);
		$lockingProvider = $this->container->query('OCP\Lock\ILockingProvider');
		$imageProcessingTask = new ImageProcessingTask($imageMapper, $faceMapper, $fileService, $settingsService, $modelManager, $lockingProvider);

		// The real pipeline sets up the file system of the user before its
		// images are processed, which is also what keeps the files of the other
		// users out of reach here: the same file id resolves in each account.
		$fileService->setupFS($userId);

		$this->context->propertyBag['images'] = $images;
		$this->context->propertyBag['run_mode'] = $runMode;

		$generator = $imageProcessingTask->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());
	}

	/**
	 * Helper method to set up and do scanning for a user.
	 */
	private function doMissingImageScan(string $userId): void {
		$this->config->setUserValue($userId, 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');

		$imageMapper = $this->container->query(ImageMapper::class);
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query(SettingsService::class);
		$addMissingImagesTask = new AddMissingImagesTask($imageMapper, $fileService, $settingsService);

		$userManager = $this->container->query('OCP\IUserManager');
		$this->context->user = $userManager->get($userId);

		$generator = $addMissingImagesTask->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());
	}

	/**
	 * Helper method to create the other user of the tests.
	 *
	 * @return IUser The created user
	 */
	private function createOtherUser(): IUser {
		$userManager = $this->container->query('OCP\IUserManager');
		$username = 'testuser' . rand(0, PHP_INT_MAX);
		$this->otherUser = $userManager->createUser($username, 'password');
		$this->config->setUserValue($this->otherUser->getUID(), 'facerecognition', 'enabled', 'true');
		return $this->otherUser;
	}

	/**
	 * Helper method to add an image for a user, sharing the file id with the
	 * owner, which is what a shared photo looks like in the tables.
	 *
	 * @param IUser $user User to add the image for
	 * @param int $fileId File id of the photo
	 *
	 * @return Image The added image
	 */
	private function addImageForUser(IUser $user, int $fileId): Image {
		$imageMapper = $this->container->query(ImageMapper::class);
		$image = new Image();
		$image->setUser($user->getUID());
		$image->setFile($fileId);
		$image->setModel(self::MODEL_ID);
		$imageMapper->insert($image);
		return $image;
	}
}

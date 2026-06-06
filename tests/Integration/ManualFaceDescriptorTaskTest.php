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

use OC\Files\View;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ManualFaceDescriptorTask;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;

use OCA\FaceRecognition\Model\ModelManager;

/**
 * Integration coverage for ManualFaceDescriptorTask: a manual face flagged for
 * clustering should get a real descriptor when the model finds a face in the
 * marked region, and should be excluded from clustering (no crash, no fake
 * descriptor) when it does not.
 */
class ManualFaceDescriptorTaskTest extends IntegrationTestCase {

	public function setUp(): void {
		parent::setUp();

		$this->originalMinImageSize = intval($this->config->getAppValue('facerecognition', 'min_image_size', '512'));
		$this->originalMaxImageArea = intval($this->config->getAppValue('facerecognition', 'max_image_area', 0));
		$this->config->setAppValue('facerecognition', 'min_image_size', 1);
		$this->config->setAppValue('facerecognition', 'max_image_area', 200 * 200);

		$model = $this->container->query('OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model');
		$model->install();
	}

	public function tearDown(): void {
		$this->config->setAppValue('facerecognition', 'min_image_size', $this->originalMinImageSize);
		$this->config->setAppValue('facerecognition', 'max_image_area', $this->originalMaxImageArea);

		parent::tearDown();
	}

	/**
	 * A manual face drawn over a real face gets a descriptor and stays groupable.
	 */
	public function testDescriptorComputedForRealFace() {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		// A generous box that covers the face in lenna.jpg (512x512).
		$faceId = $this->setupManualFace('lenna.jpg', 20, 20, 472, 472);

		$this->runManualFaceDescriptorTask();

		$face = $faceMapper->find($faceId);
		$this->assertNotEmpty($face->descriptor, 'A descriptor must be computed for the marked face');

		// No longer pending: it now has a descriptor and will be clustered.
		$pending = $faceMapper->findManualFacesPendingDescriptor($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertCount(0, $pending);
	}

	/**
	 * A manual face drawn where there is no face is excluded from clustering
	 * (no descriptor, no exception).
	 */
	public function testNoFaceLeavesFaceWithoutDescriptor() {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		$faceId = $this->setupManualFace('black.jpg', 10, 10, 100, 100);

		$this->runManualFaceDescriptorTask();

		$face = $faceMapper->find($faceId);
		$this->assertEmpty($face->descriptor, 'No descriptor should be stored when no face is found');

		// It gave up (is_groupable = false) and is not retried.
		$pending = $faceMapper->findManualFacesPendingDescriptor($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertCount(0, $pending);
	}

	/**
	 * Upload an asset, register its image, and insert a manual face flagged for
	 * clustering over the given (original-pixel) rectangle.
	 *
	 * @return int the inserted face id
	 */
	private function setupManualFace(string $asset, int $x, int $y, int $w, int $h): int {
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		// Upload the asset and let the scan register an image row for it.
		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		$view->file_put_contents($asset, file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/' . $asset));
		$this->doMissingImageScan($this->user);

		$images = $imageMapper->findImages($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($images));
		$imageId = $images[0]->getId();

		$face = new Face();
		$face->setImage($imageId);
		$face->setX($x);
		$face->setY($y);
		$face->setWidth($w);
		$face->setHeight($h);
		$face->setConfidence(1.0);
		$face->landmarks = [];
		$face->descriptor = [];
		$face->isGroupable = true; // flagged for clustering -> pending descriptor
		$face = $faceMapper->insertManualFace($face);

		// Precondition: it is pending before the task runs.
		$pending = $faceMapper->findManualFacesPendingDescriptor($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertCount(1, $pending);

		return $face->getId();
	}

	private function runManualFaceDescriptorTask(): void {
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');
		$modelManager = $this->container->query('OCA\FaceRecognition\Model\ModelManager');
		$tempManager = $this->container->query('OCP\ITempManager');

		$task = new ManualFaceDescriptorTask($faceMapper, $fileService, $settingsService, $modelManager, $tempManager);
		$this->assertNotEquals("", $task->description());

		$this->context->user = $this->user;

		$generator = $task->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());
	}

	private function doMissingImageScan($contextUser = null): void {
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');

		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$fileService = $this->container->query('OCA\FaceRecognition\Service\FileService');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');
		$addMissingImagesTask = new AddMissingImagesTask($imageMapper, $fileService, $settingsService);

		$this->context->user = $contextUser;

		$generator = $addMissingImagesTask->execute($this->context);
		foreach ($generator as $_) {
		}
		$this->assertEquals(true, $generator->getReturn());
	}
}

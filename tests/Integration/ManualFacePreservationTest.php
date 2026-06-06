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

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Model\ModelManager;

/**
 * Regression test for manual-face preservation.
 *
 * When an image is (re)processed, ImageMapper::imageProcessed() replaces the
 * model-detected faces. Manually added faces (is_manual = true) carry no model
 * descriptor and cannot be re-detected, so they must survive re-processing.
 * Before the fix the blanket DELETE wiped them, silently destroying user data.
 */
class ManualFacePreservationTest extends IntegrationTestCase {

	public function testImageProcessedKeepsManualFaces(): void {
		/** @var ImageMapper $imageMapper */
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		/** @var FaceMapper $faceMapper */
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');

		$userId  = $this->user->getUID();
		$modelId = ModelManager::DEFAULT_FACE_MODEL_ID;

		// An image that has already been processed once.
		$image = new Image();
		$image->setUser($userId);
		$image->setFile(123456);
		$image->setModel($modelId);
		$image->setIsProcessed(true);
		$image = $imageMapper->insert($image);

		// A model-detected face (has a descriptor, is_manual stays false) ...
		$detected = $faceMapper->insertFace($this->makeFace($image->getId(), [0.1, 0.2, 0.3]));

		// ... and a user-added manual face (no descriptor, is_manual = true).
		$manual = $faceMapper->insertManualFace($this->makeFace($image->getId(), []));

		$this->assertCount(2, $faceMapper->getFaces($userId, $modelId));

		// Re-process the image: detection now returns a single (different) face.
		$reDetected = $this->makeFace($image->getId(), [0.4, 0.5, 0.6]);
		$imageMapper->imageProcessed($image, [$reDetected], 5);

		// The old detected face is gone; the manual face and the freshly
		// detected face remain.
		$idsAfter = [];
		foreach ($faceMapper->getFaces($userId, $modelId) as $face) {
			$idsAfter[] = $face->getId();
		}

		$this->assertCount(2, $idsAfter, 'Expected manual face + freshly detected face');
		$this->assertContains($manual->getId(), $idsAfter, 'Manual face must survive re-processing');
		$this->assertNotContains($detected->getId(), $idsAfter, 'Old detected face should be replaced');
	}

	/**
	 * Build a Face fixture for the given image with the given descriptor.
	 * insertManualFace() forces is_manual=true and an empty descriptor on its
	 * own, so this fixture works for both detected and manual faces.
	 *
	 * @param float[] $descriptor
	 */
	private function makeFace(int $imageId, array $descriptor): Face {
		$face = new Face();
		$face->setImage($imageId);
		$face->setX(10);
		$face->setY(10);
		$face->setWidth(50);
		$face->setHeight(50);
		$face->setConfidence(1.0);
		$face->landmarks = [];
		$face->descriptor = $descriptor;
		$face->isGroupable = false;
		$face->setCreationTime(new \DateTime());
		return $face;
	}
}

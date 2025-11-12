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

use OCA\FaceRecognition\Service\FaceManagementService;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Model\ModelManager;


use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(FaceManagementService::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Face::class)]
#[UsesClass(\OCA\FaceRecognition\Db\FaceMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Image::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ImageMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Person::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ClusterMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Service\SettingsService::class)]
class ResetAllTest extends IntegrationTestCase {

	/**
	 * Test that AddMissingImagesTask is updating app config that it finished full scan.
	 * Note that, in this test, we cannot check number of newly found images,
	 * as this instance might be in use and can lead to wrong results
	 */
	public function testResetAll() {
		// Add one image to DB
		$image = new Image();
		$image->setUser(self::$user->getUid());
		$image->setFile(1);
		$image->setModel(ModelManager::DEFAULT_FACE_MODEL_ID);
		$image = self::$imageMapper->insert($image);
		$imageCount = self::$imageMapper->countUserImages(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $imageCount);

		// Add one person to DB
		$person = new Person();
		$person->setUser(self::$user->getUID());
		$person->setIsValid(true);
		$person->setName('foo');
		$person = self::$clusterMapper->insert($person);
		$personCount = self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $personCount); // Still 0 due it has no associated faces

		// Add one face to DB
		$face = Face::fromModel($image->getId(), array("left"=>0, "right"=>100, "top"=>0, "bottom"=>100, "detection_confidence"=>1.0));
		$face->setPerson($person->getId());
		$face = self::$faceMapper->insertFace($face);
		$faceCount = self::$faceMapper->countFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $faceCount);

		// Check faces with all correct relationships
		$personCount = self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $personCount);

		// Execute reset all
		$faceMgmtService = new FaceManagementService(self::$userManager, self::$faceMapper, self::$imageMapper, self::$clusterMapper, self::$settingsService);
		$faceMgmtService->resetAllForUser(self::$user->getUID());

		// Check that everything is gone
		$imageCount = self::$imageMapper->countUserImages(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $imageCount);
		$faceCount = self::$faceMapper->countFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $faceCount);
		$personCount = self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $personCount);
	}
}
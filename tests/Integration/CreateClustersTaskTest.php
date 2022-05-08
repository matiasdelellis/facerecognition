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

class CreateClustersTaskTest extends IntegrationTestCase {

	/**
	 * Test that one face that was not in any cluster will be assigned new person
	 */
	public function testCreateSingleFaceCluster() {
		$personMapper = $this->container->query('OCA\FaceRecognition\Db\PersonMapper');
		$imageMapper = $this->container->query('OCA\FaceRecognition\Db\ImageMapper');
		$faceMapper = $this->container->query('OCA\FaceRecognition\Db\FaceMapper');
		$settingsService = $this->container->query('OCA\FaceRecognition\Service\SettingsService');

		$image = new Image();
		$image->setUser($this->user->getUid());
		$image->setFile(1);
		$image->setModel(ModelManager::DEFAULT_FACE_MODEL_ID);
		$imageMapper->insert($image);

		$face = Face::fromModel($image->getId(), array("left"=>0, "right"=>100, "top"=>0, "bottom"=>100, "detection_confidence"=>1.0));
		$faceMapper->insertFace($face);

		// With a single face should never create clusters.
		$this->doCreateClustersTask($personMapper, $imageMapper, $faceMapper, $settingsService, $this->user);

		$personCount = $personMapper->countPersons($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $personCount);
		$persons = $personMapper->findAll($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, count($persons));

		$faceCount = $faceMapper->countFaces($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $faceCount);
		$faces = $faceMapper->getFaces($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertNull($faces[0]->getPerson());

		// Force clustering the sigle face.
		$settingsService->_setForceCreateClusters(true, $this->user->getUID());

		$this->doCreateClustersTask($personMapper, $imageMapper, $faceMapper, $settingsService, $this->user);

		$clusterCount = $personMapper->countClusters($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $clusterCount);

		$persons = $personMapper->findAll($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($persons));
		$personId = $persons[0]->getId();

		$faceCount = $faceMapper->countFaces($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $faceCount);

		$faces = $faceMapper->getFaces($this->user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertNotNull($faces[0]->getPerson());

		$faces = $faceMapper->findFacesFromPerson($this->user->getUID(), $personId, ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($faces));
	}

	/**
	 * Helper method to set up and do create clusters task
	 *
	 * @param IUser|null $contextUser Optional user to create clusters for.
	 * If not given, clusters for all users will be processed.
	 */
	private function doCreateClustersTask($personMapper, $imageMapper, $faceMapper, $settingsService, $contextUser = null) {
		$createClustersTask = new CreateClustersTask($personMapper, $imageMapper, $faceMapper, $settingsService);
		$this->assertNotEquals("", $createClustersTask->description());

		// Set user for which to do processing, if any
		$this->context->user = $contextUser;

		// Since this task returns generator, iterate until it is done
		$generator = $createClustersTask->execute($this->context);
		foreach ($generator as $_) {
		}

		$this->assertEquals(true, $generator->getReturn());
	}
}
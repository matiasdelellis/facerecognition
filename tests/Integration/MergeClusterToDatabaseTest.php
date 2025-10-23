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

use OCP\AppFramework\Db\DoesNotExistException;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\Person;
use \OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Model\ModelManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ClusterMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Face::class)]
#[UsesClass(\OCA\FaceRecognition\Db\FaceMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Image::class)]
#[UsesClass(\OCA\FaceRecognition\Db\ImageMapper::class)]
#[UsesClass(\OCA\FaceRecognition\Db\Person::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext::class)]
#[UsesClass(\OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger::class)]
#[UsesClass(\OCA\FaceRecognition\Service\SettingsService::class)]
class MergeClusterToDatabaseTest extends IntegrationTestCase {

	public function testMergeEmptyClusterToDatabase() {
		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(), array(), array());

		$personCount =self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $personCount);
	}

	/**
	 * Test when [] changes to p1=>[f1]
	 * (test that new person is created)
	 */
	public function testCreatePerson() {

		$image = $this->createImage();
		$face = $this->createFace($image->getId());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(), array(), array(100=>[$face->getId()]));

		$personId = $this->assertOnePerson();
		$this->assertFaces([$personId => [$face->getId()]]);
	}

	/**
	 * Test when p1=>[f1] changes to p1=>[f1]
	 * (test that nothing happens when input and output clusters are the same)
	 */
	public function testSamePerson() {

		$person = $this->createPerson();
		$image = $this->createImage();
		$face = $this->createFace($image->getId(), $person->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array($person->getId() => [$face->getId()]),
			array($person->getId() => [$face->getId()]));

		$personId = $this->assertOnePerson("foo");
		$this->assertFaces([$personId => [$face->getId()]]);
	}

	/**
	 * Test when p1=>[f1] changes to p2=>[f1]
	 * (test that new person is created and old one deleted when face changes person)
	 */
	public function testChangePerson() {

		$person = $this->createPerson();
		$image = $this->createImage();
		$face = $this->createFace($image->getId(), $person->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array($person->getId() => [$face->getId()]),
			array($person->getId()+1 => [$face->getId()])
		);

		$this->assertPersonDoNotExist($person->getId());
		$personId = $this->assertOnePerson();
		$this->assertFaces([$personId => [$face->getId()]]);
	}

	/**
	 * Test when p1=>[f1] changes to []
	 * (old person p1 should be deleted and face f1 resets its personId to null)
	 */
	public function testNoPersons() {

		$person = $this->createPerson();
		$image = $this->createImage();
		$face = $this->createFace($image->getId(), $person->getId());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array($person->getId() => [$face->getId()]),
			array());

		$personCount =self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, $personCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, count($persons));
		$this->assertPersonDoNotExist($person->getId());

		$this->assertFaces([null => [$face->getId()]]);

		$faceCount = self::$faceMapper->countFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $faceCount);
		$faces = self::$faceMapper->getFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($faces));
		$this->assertNull($faces[0]->getPerson());
		$faces = self::$faceMapper->findFromCluster(self::$user->getUID(), $person->getId(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(0, count($faces));
	}

	/**
	 * Test when p1=>[f1, f2] changes to p2=>[f1], p3=>[f2]
	 * (old person p1 should be deleted, and p2, p3 should be created)
	 */
	public function testSplitToNewPersons() {
		$person = $this->createPerson();
		$image = $this->createImage();
		$face1 = $this->createFace($image->getId(), $person->getId());
		$face2 = $this->createFace($image->getId(), $person->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		$ret = self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person->getId() => [$face1->getId(), $face2->getId()]
			),
			array(
				$person->getId()+1 => [$face1->getId()],
				$person->getId()+2 => [$face2->getId()]
			)
		);

		$clusterCount =self::$clusterMapper->countClusters(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, $clusterCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, count($persons));
		usort($persons, function($p1, $p2) {
			return $p1->getId() - $p2->getId();
		});
		$this->assertTrue(is_null($persons[0]->getName()));
		$this->assertTrue(is_null($persons[1]->getName()));
		$this->assertTrue($persons[0]->getIsValid());
		$this->assertTrue($persons[1]->getIsValid());
		$this->assertPersonDoNotExist($person->getId());

		$person1Id = $persons[0]->getId();
		$person2Id = $persons[1]->getId();
		$this->assertFaces([$person1Id => [$face1->getId()], $person2Id => [$face2->getId()]]);
	}

	/**
	 * Test when p1=>[f1, f2] changes to p1=>[f1], p2=>[f2]
	 * (new person p2 should be created, f2 should change person)
	 */
	public function testSplitToSamePerson() {

		$person = $this->createPerson();
		$image = $this->createImage();
		$face1 = $this->createFace($image->getId(), $person->getId());
		$face2 = $this->createFace($image->getId(), $person->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person->getId() => [$face1->getId(), $face2->getId()]
			),
			array(
				$person->getId() => [$face1->getId()],
				$person->getId()+1 => [$face2->getId()]
			)
		);

		$clusterCount =self::$clusterMapper->countClusters(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, $clusterCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, count($persons));
		usort($persons, function($p1, $p2) {
			return $p1->getId() - $p2->getId();
		});
		$this->assertTrue($persons[0]->getName() === 'foo');
		$this->assertTrue($persons[1]->getName() === null);
		$this->assertTrue($persons[0]->getIsValid());
		$this->assertTrue($persons[1]->getIsValid());
		$person1Id = $persons[0]->getId();
		$person2Id = $persons[1]->getId();
		$this->assertEquals($person1Id, $person->getId());

		$this->assertFaces([$person1Id => [$face1->getId()], $person2Id => [$face2->getId()]]);
	}

	/**
	 * Test when p1=>[f1], p2=>[f2] changes to p1=>[f1, f2], p2=>[]
	 * (old person p2 should be deleted, and p1 should be re-populated with both faces)
	 */
	public function testMergeToSamePersons() {

		$person1 = $this->createPerson('foo');
		$person2 = $this->createPerson('bar');
		$image = $this->createImage();
		$face1 = $this->createFace($image->getId(), $person1->getId());
		$face2 = $this->createFace($image->getId(), $person2->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person1->getId() => [$face1->getId()],
				$person2->getId() => [$face2->getId()],
			),
			array(
				$person1->getId() => [$face1->getId(), $face2->getId()]
			)
		);

		$this->assertPersonDoNotExist($person2->getId());
		$personId = $this->assertOnePerson('foo');
		$this->assertEquals($personId, $person1->getId());
		$this->assertFaces([$personId => [$face1->getId(), $face2->getId()]]);
	}

	/**
	 * Test when p1=>[f1], p2=>[f2] changes to p1=>[], p2=[], p3=>[f1, f2]
	 * (old persons p1 and p2 should be deleted, and p3 should be re-populated with both faces)
	 */
	public function testMergeToNewPersons() {

		$person1 = $this->createPerson('foo');
		$person2 = $this->createPerson('bar');
		$image = $this->createImage();
		$face1 = $this->createFace($image->getId(), $person1->getId());
		$face2 = $this->createFace($image->getId(), $person2->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person1->getId() => [$face1->getId()],
				$person2->getId() => [$face2->getId()],
			),
			array(
				$person1->getId()+5 => [$face1->getId(), $face2->getId()]
			)
		);

		$this->assertPersonDoNotExist($person1->getId());
		$this->assertPersonDoNotExist($person2->getId());
		$personId = $this->assertOnePerson();
		$this->assertFaces([$personId => [$face1->getId(), $face2->getId()]]);
	}

	/**
	 * Test when p1=>[f1], p2=>[f2] changes to p1=>[f2], p2=>[f1]
	 * (both persons and faces stay, but they change who is who)
	 */
	public function testSwap() {

		$person1 = $this->createPerson('foo');
		$person2 = $this->createPerson('bar');
		$image = $this->createImage();
		$face1 = $this->createFace($image->getId(), $person1->getId());
		$face2 = $this->createFace($image->getId(), $person2->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person1->getId() => [$face1->getId()],
				$person2->getId() => [$face2->getId()],
			),
			array(
				$person1->getId() => [$face2->getId()],
				$person2->getId() => [$face1->getId()],
			)
		);

		$personCount =self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, $personCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, count($persons));
		usort($persons, function($p1, $p2) {
			return $p1->getId() - $p2->getId();
		});
		$this->assertTrue(strpos($persons[0]->getName(), 'foo') !== false);
		$this->assertTrue(strpos($persons[1]->getName(), 'bar') !== false);
		$this->assertTrue($persons[0]->getIsValid());
		$this->assertTrue($persons[1]->getIsValid());
		$this->assertEquals($person1->getId(), $persons[0]->getId());
		$this->assertEquals($person2->getId(), $persons[1]->getId());

		$this->assertFaces([$person1->getId() => [$face2->getId()], $person2->getId() => [$face1->getId()]]);
	}

	/**
	 * Test when p1=>[f1], p2=>[f2] changes to p1=>[f2], p3=>[f1]
	 * (p2 is lost and p3 is created. f2 is swapped to existing person p1)
	 */
	public function testHalfSwap() {

		$person1 = $this->createPerson('foo');
		$person2 = $this->createPerson('bar');
		$image = $this->createImage();
		$face1 = $this->createFace($image->getId(), $person1->getId());
		$face2 = $this->createFace($image->getId(), $person2->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person1->getId() => [$face1->getId()],
				$person2->getId() => [$face2->getId()],
			),
			array(
				$person1->getId() => [$face2->getId()],
				$person2->getId()+5 => [$face1->getId()],
			)
		);

		$clusterCount =self::$clusterMapper->countClusters(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, $clusterCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(2, count($persons));
		usort($persons, function($p1, $p2) {
			return $p1->getId() - $p2->getId();
		});
		$this->assertTrue($persons[0]->getName() === 'foo');
		$this->assertTrue($persons[1]->getName() === null);
		$this->assertTrue($persons[0]->getIsValid());
		$this->assertTrue($persons[1]->getIsValid());
		$this->assertEquals($person1->getId(), $persons[0]->getId());
		$person3Id = $persons[1]->getId();

		$this->assertFaces([$person1->getId() => [$face2->getId()], $person3Id => [$face1->getId()]]);
	}

	/**
	 * Same test as MergeClustersTest::testMergeClustersComplex
	 * p1=>[f1,f2,f3,f4], p2=>[f5,f6,f7,f8], p3=>[f9,f10,f11,f12], p4=>[f13,f14,f15,f16]
	 * is changed to
	 * p1=>[f1,f2,f3,f4] (same as before)
	 * p2=>[f5,f6,f7,f8,f17] (added f17)
	 * p3=>[f9,f10,f11] (removed f12)
	 * p4=>[f13,f14,f15] (removed f16)
	 * p5=>[f16,f18] (new person, moved f16 here plus new face f18)
	 * p6=>[f19,f20,f21] (new person, all new faces)
	 */
	public function testComplexToSamePerson() {

		$person1 = $this->createPerson('foo-p1');
		$person2 = $this->createPerson('foo-p2');
		$person3 = $this->createPerson('foo-p3');
		$person4 = $this->createPerson('foo-p4');
		$image = $this->createImage();
		$face1  = $this->createFace($image->getId(), $person1->getId());
		$face2  = $this->createFace($image->getId(), $person1->getId());
		$face3  = $this->createFace($image->getId(), $person1->getId());
		$face4  = $this->createFace($image->getId(), $person1->getId());
		$face5  = $this->createFace($image->getId(), $person2->getId());
		$face6  = $this->createFace($image->getId(), $person2->getId());
		$face7  = $this->createFace($image->getId(), $person2->getId());
		$face8  = $this->createFace($image->getId(), $person2->getId());
		$face9  = $this->createFace($image->getId(), $person3->getId());
		$face10 = $this->createFace($image->getId(), $person3->getId());
		$face11 = $this->createFace($image->getId(), $person3->getId());
		$face12 = $this->createFace($image->getId(), $person3->getId());
		$face13 = $this->createFace($image->getId(), $person4->getId());
		$face14 = $this->createFace($image->getId(), $person4->getId());
		$face15 = $this->createFace($image->getId(), $person4->getId());
		$face16 = $this->createFace($image->getId(), $person4->getId());
		$face17 = $this->createFace($image->getId());
		$face18 = $this->createFace($image->getId());
		$face19 = $this->createFace($image->getId());
		$face20 = $this->createFace($image->getId());
		$face21 = $this->createFace($image->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		// First person is not invalid (it will remain same, so change it back to valid)
		$person1->setIsValid(true);
		self::$clusterMapper->update($person1);

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person1->getId() => [$face1->getId(), $face2->getId(), $face3->getId(), $face4->getId()],
				$person2->getId() => [$face5->getId(), $face6->getId(), $face7->getId(), $face8->getId()],
				$person3->getId() => [$face9->getId(), $face10->getId(), $face11->getId(), $face12->getId()],
				$person4->getId() => [$face13->getId(), $face14->getId(), $face15->getId(), $face16->getId()],
			),
			array(
				$person1->getId() => [$face1->getId(), $face2->getId(), $face3->getId(), $face4->getId()],
				$person2->getId() => [$face5->getId(), $face6->getId(), $face7->getId(), $face8->getId(), $face17->getId()],
				$person3->getId() => [$face9->getId(), $face10->getId(), $face11->getId()],
				$person4->getId() => [$face13->getId(), $face14->getId(), $face15->getId()],
				$person1->getId() + 100 => [$face16->getId(), $face18->getId()],
				$person1->getId() + 101 => [$face19->getId(), $face20->getId(), $face21->getId()],
			)
		);

		$clusterCount =self::$clusterMapper->countClusters(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(6, $clusterCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(6, count($persons));
		usort($persons, function($p1, $p2) {
			return $p1->getId() - $p2->getId();
		});
		$this->assertTrue($persons[0]->getName() === 'foo-p1');
		$this->assertTrue($persons[1]->getName() === 'foo-p2');
		$this->assertTrue($persons[2]->getName() === 'foo-p3');
		$this->assertTrue($persons[3]->getName() === 'foo-p4');
		$this->assertTrue($persons[4]->getName() === null);
		$this->assertTrue($persons[5]->getName() === null);
		foreach ($persons as $person) {
			$this->assertTrue($person->getIsValid());
		}
		$this->assertEquals($person1->getId(), $persons[0]->getId());
		$this->assertEquals($person2->getId(), $persons[1]->getId());
		$this->assertEquals($person3->getId(), $persons[2]->getId());
		$this->assertEquals($person4->getId(), $persons[3]->getId());
		$person5Id = $persons[4]->getId();
		$person6Id = $persons[5]->getId();

		$this->assertFaces([
			$person1->getId() => [$face1->getId(), $face2->getId(), $face3->getId(), $face4->getId()],
			$person2->getId() => [$face5->getId(), $face6->getId(), $face7->getId(), $face8->getId(), $face17->getId()],
			$person3->getId() => [$face9->getId(), $face10->getId(), $face11->getId()],
			$person4->getId() => [$face13->getId(), $face14->getId(), $face15->getId()],
			$person5Id => [$face16->getId(), $face18->getId()],
			$person6Id => [$face19->getId(), $face20->getId(), $face21->getId()],
			null => [$face12->getId()]
		]);
	}

	/**
	 * Same test as MergeClustersTest::testMergeClustersComplex
	 * and here same as testComplexToSamePerson, but with a twist that all persons are new.
	 * p1=>[f1,f2,f3,f4], p2=>[f5,f6,f7,f8], p3=>[f9,f10,f11,f12], p4=>[f13,f14,f15,f16]
	 * is changed to
	 * p5=>[f1,f2,f3,f4] (same as before)
	 * p6=>[f5,f6,f7,f8,f17] (added f17)
	 * p7=>[f9,f10,f11] (removed f12)
	 * p8=>[f13,f14,f15] (removed f16)
	 * p9=>[f16,f18] (new person, moved f16 here plus new face f18)
	 * p10=>[f19,f20,f21] (new person, all new faces)
	 */
	public function testComplexToNewPerson() {

		$person1 = $this->createPerson();
		$person2 = $this->createPerson();
		$person3 = $this->createPerson();
		$person4 = $this->createPerson();
		$image = $this->createImage();
		$face1  = $this->createFace($image->getId(), $person1->getId());
		$face2  = $this->createFace($image->getId(), $person1->getId());
		$face3  = $this->createFace($image->getId(), $person1->getId());
		$face4  = $this->createFace($image->getId(), $person1->getId());
		$face5  = $this->createFace($image->getId(), $person2->getId());
		$face6  = $this->createFace($image->getId(), $person2->getId());
		$face7  = $this->createFace($image->getId(), $person2->getId());
		$face8  = $this->createFace($image->getId(), $person2->getId());
		$face9  = $this->createFace($image->getId(), $person3->getId());
		$face10 = $this->createFace($image->getId(), $person3->getId());
		$face11 = $this->createFace($image->getId(), $person3->getId());
		$face12 = $this->createFace($image->getId(), $person3->getId());
		$face13 = $this->createFace($image->getId(), $person4->getId());
		$face14 = $this->createFace($image->getId(), $person4->getId());
		$face15 = $this->createFace($image->getId(), $person4->getId());
		$face16 = $this->createFace($image->getId(), $person4->getId());
		$face17 = $this->createFace($image->getId());
		$face18 = $this->createFace($image->getId());
		$face19 = $this->createFace($image->getId());
		$face20 = $this->createFace($image->getId());
		$face21 = $this->createFace($image->getId());
		self::$clusterMapper->invalidatePersons($image->getId(), self::$user->getUID());

		self::$clusterMapper->mergeClusterToDatabase(self::$user->getUid(),
			array(
				$person1->getId() => [$face1->getId(), $face2->getId(), $face3->getId(), $face4->getId()],
				$person2->getId() => [$face5->getId(), $face6->getId(), $face7->getId(), $face8->getId()],
				$person3->getId() => [$face9->getId(), $face10->getId(), $face11->getId(), $face12->getId()],
				$person4->getId() => [$face13->getId(), $face14->getId(), $face15->getId(), $face16->getId()],
			),
			array(
				$person1->getId() + 100 => [$face1->getId(), $face2->getId(), $face3->getId(), $face4->getId()],
				$person1->getId() + 101 => [$face5->getId(), $face6->getId(), $face7->getId(), $face8->getId(), $face17->getId()],
				$person1->getId() + 102 => [$face9->getId(), $face10->getId(), $face11->getId()],
				$person1->getId() + 103 => [$face13->getId(), $face14->getId(), $face15->getId()],
				$person1->getId() + 104 => [$face16->getId(), $face18->getId()],
				$person1->getId() + 105 => [$face19->getId(), $face20->getId(), $face21->getId()],
			)
		);

		$clusterCount =self::$clusterMapper->countClusters(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(6, $clusterCount);
		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(6, count($persons));
		usort($persons, function($p1, $p2) {
			return $p1->getId() - $p2->getId();
		});
		$this->assertPersonDoNotExist($person1->getId());
		$this->assertPersonDoNotExist($person2->getId());
		$this->assertPersonDoNotExist($person3->getId());
		$this->assertPersonDoNotExist($person4->getId());
		$this->assertTrue($persons[0]->getName() === null);
		$this->assertTrue($persons[1]->getName() === null);
		$this->assertTrue($persons[2]->getName() === null);
		$this->assertTrue($persons[3]->getName() === null);
		$this->assertTrue($persons[4]->getName() === null);
		$this->assertTrue($persons[5]->getName() === null);
		foreach ($persons as $person) {
			$this->assertTrue($person->getIsValid());
		}
		$person5Id = $persons[0]->getId();
		$person6Id = $persons[1]->getId();
		$person7Id = $persons[2]->getId();
		$person8Id = $persons[3]->getId();
		$person9Id = $persons[4]->getId();
		$person10Id = $persons[5]->getId();

		$this->assertFaces([
			$person5Id => [$face1->getId(), $face2->getId(), $face3->getId(), $face4->getId()],
			$person6Id => [$face5->getId(), $face6->getId(), $face7->getId(), $face8->getId(), $face17->getId()],
			$person7Id => [$face9->getId(), $face10->getId(), $face11->getId()],
			$person8Id => [$face13->getId(), $face14->getId(), $face15->getId()],
			$person9Id => [$face16->getId(), $face18->getId()],
			$person10Id => [$face19->getId(), $face20->getId(), $face21->getId()],
			null => [$face12->getId()]
		]);
	}

	private function createPerson($name = 'foo'): Person {
		$person = new Person();
		$person->setUser(self::$user->getUID());
		$person->setName($name);
		$person->setIsValid(true);
		self::$clusterMapper->insert($person);

		return $person;
	}

	private function createImage(): Image {
		$image = new Image();
		$image->setUser(self::$user->getUid());
		$image->setFile(1);
		$image->setModel(ModelManager::DEFAULT_FACE_MODEL_ID);
		self::$imageMapper->insert($image);

		return $image;
	}

	private function createFace($imageId, $personId = null) {
		$face = Face::fromModel($imageId, array("left"=>0, "right"=>100, "top"=>0, "bottom"=>100, "detection_confidence"=>1.0));
		if ($personId !== null) {
			$face->setPerson($personId);
		}
		self::$faceMapper->insertFace($face);
		return $face;
	}

	private function assertOnePerson($name = null): int {
		$clusterCount =self::$clusterMapper->countClusters(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, $clusterCount);

		$persons =self::$clusterMapper->findAll(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals(1, count($persons));

		if ($name !== null) {
			// check that retains the name
			$this->assertTrue($persons[0]->getName() === $name);

			$personCount =self::$clusterMapper->countPersons(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
			$this->assertEquals(1, $personCount);

			// Check that it can be found using this method too
			$persons =self::$clusterMapper->findByName(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID, $name);
			$this->assertEquals(1, count($persons));
		}

		// Check that it can be found using this method too
		self::$clusterMapper->find(self::$user->getUID(), $persons[0]->getId());

		// After clustering, person must be valid
		$this->assertTrue($persons[0]->getIsValid());

		return $persons[0]->getId();
	}

	private function assertPersonDoNotExist(int $personId) {
		try {
			self::$clusterMapper->find(self::$user->getUID(), $personId);
			$this->fail('Person still exist');
		} catch (DoesNotExistException $e) {
		}
	}

	/**
	 * Checks given array of faces exist in database and nothing more, and checks that faces are associated to persons.
	 * Keys in arrray are person IDs, and values are arrays with face IDs:
	 * [p1=>[f1], p2=>[f2, f3]...]
	 * It does as much asserts as possible by getting data from database. If key is empty, that means that face do not have person.
	 */
	private function assertFaces(array $personToFaces) {
		$totalFaces = 0;
		foreach ($personToFaces as $person=>$faces) {
			$totalFaces += count($faces);
		}

		// Check total faces in DB
		$faceCount = self::$faceMapper->countFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals($totalFaces, $faceCount);

		// Check those faces have given persons
		$facesDb = self::$faceMapper->getFaces(self::$user->getUID(), ModelManager::DEFAULT_FACE_MODEL_ID);
		$this->assertEquals($totalFaces, count($facesDb));
		foreach($facesDb as $faceDb) {
			foreach ($personToFaces as $person=>$faces) {
				if (in_array($faceDb->getId(), $faces)) {
					if ($person !== "") {
						$this->assertEquals($faceDb->getPerson(), $person);
					} else {
						$this->assertNull($faceDb->getPerson());
					}
				}
			}
		}

		// Check that each person have those faces (and no more)
		foreach($personToFaces as $person=>$faces) {
			if ($person === "") {
				continue;
			}

			$facesFromPerson = self::$faceMapper->findFromCluster(self::$user->getUID(), $person, ModelManager::DEFAULT_FACE_MODEL_ID);
			$this->assertEquals(count($faces), count($facesFromPerson));

			usort($facesFromPerson, function($f1, $f2) {
				return $f1->getId() - $f2->getId();
			});
			for ($i = 0; $i < count($faces); $i++) {
				$this->assertEquals($faces[$i], $facesFromPerson[$i]->getId());
			}
		}
	}
}
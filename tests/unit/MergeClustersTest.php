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
namespace OCA\FaceRecognition\Tests\Unit;

use Test\TestCase;

use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;

class MergeClustersTest extends TestCase {
	/** @var CreateClustersTask Create cluster task */
	private $createClusterTask;

	/**
	 * {@inheritDoc}
	 */
	public function setUp() {
		$personMapper = $this->getMockBuilder('OCA\FaceRecognition\Db\PersonMapper')
			->disableOriginalConstructor()
			->getMock();
		$imageMapper = $this->getMockBuilder('OCA\FaceRecognition\Db\ImageMapper')
			->disableOriginalConstructor()
			->getMock();
		$faceMapper = $this->getMockBuilder('OCA\FaceRecognition\Db\FaceMapper')
			->disableOriginalConstructor()
			->getMock();
		$settingsService = $this->getMockBuilder('OCA\FaceRecognition\Service\SettingsService')
			->disableOriginalConstructor()
			->getMock();
		$this->createClusterTask = new CreateClustersTask($personMapper, $imageMapper, $faceMapper, $settingsService);
	}

	/**
	 * Tests cluster merging. Starts with simple cases and go to more complex ones. IDs that are used
	 * do not have any significance, they are mostly random, except that ID<100 are for person IDs,
	 * and IDs>100 are reserved for face IDs (this is just convention in test, to make reading easier).
	 */
	public function testMergeClustersSimple() {
		// Case when old cluster is empty and we get some new clusters
		//
		$result = $this->createClusterTask->mergeClusters(array(), array(1=>[101,102], 2=>[103,104]));
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[1], [101, 102]);
		$this->assertEquals($result[2], [103, 104]);
		// Case when old and new cluster are completely same
		//
		$c = array(3=>[101,103], 4=>[105,107]);
		$result = $this->createClusterTask->mergeClusters($c, $c);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[3], [101, 103]);
		$this->assertEquals($result[4], [105, 107]);
		// Case when cluster are the same, but person ID differ
		//
		$old = array(5=>[102,103], 6=>[105,106]);
		$new = array(1=>[102,103], 2=>[105,106]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[5], [102, 103]);
		$this->assertEquals($result[6], [105, 106]);
		// Case when new faces are added to existing cluster
		//
		$old = array(7=>[102,103], 8=>[105,106]);
		$new = array(1=>[102,103], 2=>[105,106, 107]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[7], [102, 103]);
		$this->assertEquals($result[8], [105, 106, 107]);
		// Case when new faces are added to new cluster
		//
		$old = array(3=>[110,111], 4=>[112,113]);
		$new = array(1=>[110,111], 2=>[112,113], 3=>[114, 115, 116]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 3);
		$this->assertEquals($result[3], [110, 111]);
		$this->assertEquals($result[4], [112, 113]);
		$this->assertEquals($result[5], [114, 115, 116]);
		// Case when existing face "pops" to new cluster (cluster split)
		//
		$old = array(5=>[110,111,112], 6=>[113,114]);
		$new = array(1=>[110,111], 2=>[113,114], 3=>[112]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 3);
		$this->assertEquals($result[5], [110,111]);
		$this->assertEquals($result[6], [113, 114]);
		$this->assertEquals($result[7], [112]);
		// Case when existing face is removed
		//
		$old = array(7=>[110,111], 8=>[113,114]);
		$new = array(1=>[110], 2=>[113,114]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[7], [110]);
		$this->assertEquals($result[8], [113, 114]);
		// Case when all faces in cluster are removed (cluster dissapear)
		//
		$old = array(3=>[110,111], 4=>[113,114]);
		$new = array(1=>[110,111]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 1);
		$this->assertEquals($result[3], [110, 111]);
		// Case when existing faces move to other cluster (cluster spil)
		//
		$old = array(5=>[110,111], 6=>[112,113,114]);
		$new = array(1=>[110,111,112,113], 2=>[114]);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 2);
		$this->assertEquals($result[5], [110, 111,112,113]);
		$this->assertEquals($result[6], [114]);
	}

	/**
	 * More complex case demostrating various use cases
	 */
	public function testMergeClustersComplex() {
		// Case when old cluster is empty and we get some new clusters
		//
		$old = array(
			10=>[100,101,102,103],
			11=>[104,105,106,107],
			12=>[108,109,110,111],
			13=>[112,113,114,115]
		);
		$new = array(
			1=>[100,101,102,103], // not touched
			2=>[104,105,106,107,130], // new face added to this one
			3=>[108,109,110], // one face removed
			4=>[112,113,114], // one face moved to separate cluster
			5=>[115,116], // face from cluster 4 (12) plus new face in this
			6=>[117,118,119] // completely new cluster with new faces
		);
		$result = $this->createClusterTask->mergeClusters($old, $new);
		$this->assertEquals(count($result), 6);
		$this->assertEquals($result[10], [100, 101, 102, 103]);
		$this->assertEquals($result[11], [104, 105, 106, 107, 130]);
		$this->assertEquals($result[12], [108, 109, 110]);
		$this->assertEquals($result[13], [112, 113, 114]);
		$this->assertEquals($result[14], [115, 116]);
		$this->assertEquals($result[15], [117, 118, 119]);
	}
}
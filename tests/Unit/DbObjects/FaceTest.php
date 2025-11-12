<?php
/**
 * @copyright Copyright (c) 2024, Mate Zsolya <zsolyamate@gmail.com>
 *
 * @author Mate Zsolya <zsolyamate@gmail.com>
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
namespace OCA\FaceRecognition\Tests\Unit\DbOjects;

use DateTime;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use OCA\FaceRecognition\Db\Face;
use OCP\AppFramework\Db\Entity;

#[CoversClass(Face::class)]
class FaceTest extends TestCase {
    /** @var Face test instance*/
	private $face;

    /**
	* {@inheritDoc}
	*/
	public function setUp(): void {
        parent::setUp();
        $this->face = new Face();
	}
    
    public function test_jsonSerialize() : void {
        $this->face->setId(1);
        $this->face->setImage(1);
        $this->face->setPerson(2);
        $this->face->setX(10);
        $this->face->setY(20);
        $this->face->setWidth(100);
        $this->face->setHeight(200);
        $this->face->setConfidence(0.95);
        $this->face->setIsGroupable(true);
        $this->face->setLandmarks('[{"x":30,"y":40},{"x":50,"y":60},{"x":70,"y":80}]');
        $this->face->setDescriptor('[0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1.0]');
        $this->face->setCreationTime(new DateTime('2024-01-01 10:00:00'));
        $this->face->resetUpdatedFields();
        //Act
        $jsonObject = $this->face->jsonSerialize();
        //Assert
        $this->assertNotNull($jsonObject);
        $this->assertIsArray($jsonObject);
        $this->assertCount(12,$jsonObject);
        $this->assertArrayHasKey('id',$jsonObject);
        $this->assertArrayHasKey('image',$jsonObject);
        $this->assertArrayHasKey('person',$jsonObject);
        $this->assertArrayHasKey('x',$jsonObject);
        $this->assertArrayHasKey('y',$jsonObject);
        $this->assertArrayHasKey('width',$jsonObject);
        $this->assertArrayHasKey('height',$jsonObject);
        $this->assertArrayHasKey('confidence',$jsonObject);
        $this->assertArrayHasKey('is_groupable',$jsonObject);
        $this->assertArrayHasKey('landmarks',$jsonObject);
        $this->assertArrayHasKey('descriptor',$jsonObject);
        $this->assertArrayHasKey('creation_time',$jsonObject);
    }

    public function test_Landmarks() : void {
        $this->face->setLandmarks('[{"x":30,"y":40},{"x":50,"y":60},{"x":70,"y":80}]');
        //Act
        $jsonString = $this->face->getLandmarks();
        //Assert
        $this->assertNotNull($jsonString);
        $this->assertIsString($jsonString);
        $this->assertJson($jsonString);
        $this->assertEquals('[{"x":30,"y":40},{"x":50,"y":60},{"x":70,"y":80}]',$jsonString);
        $this->assertIsArray($this->face->getUpdatedFields());
        $this->assertCount(1,$this->face->getUpdatedFields());
        $this->assertArrayHasKey('landmarks',$this->face->getUpdatedFields());
    }

    public function test_Descriptor() : void { 
        $this->face->setDescriptor('[0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1.0]');
        //Act
        $jsonString = $this->face->getDescriptor();
        //Assert
        $this->assertNotNull($jsonString);
        $this->assertIsString($jsonString);
        $this->assertJson($jsonString);
        $this->assertEquals('[0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1]',$jsonString);
        $this->assertIsArray($this->face->getUpdatedFields());
        $this->assertCount(1,$this->face->getUpdatedFields());
        $this->assertArrayHasKey('descriptor',$this->face->getUpdatedFields());
    }

    public function test_CreationDate() : void { 
        $this->face->setCreationTime(new DateTime('2024-01-01 10:00:00'));
        //Act
        $creationDate = $this->face->getCreationTime();
        //Assert
        $this->assertNotNull($creationDate);
        $this->assertInstanceOf(DateTime::class,$creationDate);
        $this->assertEquals(new DateTime('2024-01-01 10:00:00'),$creationDate);
        $this->assertIsArray($this->face->getUpdatedFields());
        $this->assertCount(1,$this->face->getUpdatedFields());
        $this->assertArrayHasKey('creationTime',$this->face->getUpdatedFields());
    }
    public function test_CreationDate_withint() : void { 
        $this->face->setCreationTime(20250904100000);
        //Act
        $creationDate = $this->face->getCreationTime();
        //Assert
        $this->assertNotNull($creationDate);
        $this->assertInstanceOf(DateTime::class,$creationDate);
        $this->assertEquals(new DateTime('2025-09-04 10:00:00'),$creationDate);
        $this->assertIsArray($this->face->getUpdatedFields());
        $this->assertCount(1,$this->face->getUpdatedFields());
        $this->assertArrayHasKey('creationTime',$this->face->getUpdatedFields());
    }
    public function test_CreationDate_withstring() : void { 
        $this->face->setCreationTime('2024-01-01 10:00:00');
        //Act
        $creationDate = $this->face->getCreationTime();
        //Assert
        $this->assertNotNull($creationDate);
        $this->assertInstanceOf(DateTime::class,$creationDate);
        $this->assertEquals(new DateTime('2024-01-01 10:00:00'),$creationDate);
        $this->assertIsArray($this->face->getUpdatedFields());
        $this->assertCount(1,$this->face->getUpdatedFields());
        $this->assertArrayHasKey('creationTime',$this->face->getUpdatedFields());
    }
    public function test_CreationDate_withbool() : void { 
        $this->face->setCreationTime(false);
        //Act
        $creationDate = $this->face->getCreationTime();
        //Assert
        $this->assertNotNull($creationDate);
        $this->assertInstanceOf(DateTime::class,$creationDate);
        $this->assertIsArray($this->face->getUpdatedFields());
        $this->assertCount(1,$this->face->getUpdatedFields());
        $this->assertArrayHasKey('creationTime',$this->face->getUpdatedFields());
    }

    public function test_fromModel_filledProperly() : void {
        $face = [];
		$face['left'] = 10;
		$face['right'] = 40;
		$face['top'] = 20;
		$face['bottom'] = 30;
		$face['detection_confidence'] = 0.85;
		$face['landmarks'] = [["x"=>30,"y"=>40],["x"=>50,"y"=>60],["x"=>70,"y"=>80]];
		$face['descriptor'] = [0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1.0];
        //Act
        $faceObject = Face::fromModel(1, $face);
        //Assert
        $this->assertNotNull($faceObject);
        $this->assertInstanceOf(Face::class,$faceObject);
        $this->assertEquals(1,$faceObject->getImage());
        $this->assertEquals(10,$faceObject->getX());
        $this->assertEquals(20,$faceObject->getY());
        $this->assertEquals(30,$faceObject->getWidth());
        $this->assertEquals(10,$faceObject->getHeight());
        $this->assertEquals(0.85,$faceObject->getConfidence());
        $this->assertEquals('[{"x":30,"y":40},{"x":50,"y":60},{"x":70,"y":80}]',$faceObject->getLandmarks());
        $this->assertEquals('[0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1]',$faceObject->getDescriptor());
        
    }

    public function test_fromModel_filledEmpty() : void {
        $face = [];
		$face['left'] = 10;
		$face['right'] = 40;
		$face['top'] = 20;
		$face['bottom'] = 30;
		$face['detection_confidence'] = 0.85;
		$face['landmarks'] = null;
		$face['descriptor'] = null;
        //Act
        $faceObject = Face::fromModel(1, $face);
        //Assert
        $this->assertNotNull($faceObject);
        $this->assertInstanceOf(Face::class,$faceObject);
        $this->assertEquals(1,$faceObject->getImage());
        $this->assertEquals(10,$faceObject->getX());
        $this->assertEquals(20,$faceObject->getY());
        $this->assertEquals(30,$faceObject->getWidth());
        $this->assertEquals(10,$faceObject->getHeight());
        $this->assertEquals(0.85,$faceObject->getConfidence());
        $this->assertEquals('[]',$faceObject->getLandmarks());
        $this->assertEquals('[]',$faceObject->getDescriptor());
    }
    /**
	* {@inheritDoc}
	*/
    public function tearDown(): void {
		parent::tearDown();
	}
}
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use OCA\FaceRecognition\Db\Person;
use OCP\AppFramework\Db\Entity;

#[CoversClass(Person::class)]
class PersonTest extends TestCase {
    /** @var Person test instance*/
	private $person;

    /**
	* {@inheritDoc}
	*/
	public function setUp(): void {
        parent::setUp();
        $this->person = new Person();
        $this->person->setId(1);
        $this->person->setUser("user1");
        $this->person->setName("Some dummy name");
        $this->person->setIsVisible(true);
        $this->person->setIsValid(true);
        $this->person->setLastGenerationTime(new DateTime('2024-01-01 10:00:00'));
        $this->person->setLinkedUser("user2");
        $this->person->resetUpdatedFields();
	}

    public function test_jsonSerialize() : void {
        //Act
        $json = $this->person->jsonSerialize();
        //Assert
        $this->assertNotNull($json);
        $this->assertIsArray($json);
        $this->assertCount(7,$json);
        $this->assertArrayHasKey('id',$json);
        $this->assertArrayHasKey('user',$json);
        $this->assertArrayHasKey('name',$json);
        $this->assertArrayHasKey('is_visible',$json);
        $this->assertArrayHasKey('is_valid',$json);
        $this->assertArrayHasKey('last_generation_time',$json);
        $this->assertArrayHasKey('linked_user',$json);
    }
    
    public function test_setIsVisible_toBool() : void {
        //Act
        $this->person->setIsVisible(false);
        //Assert
        $this->assertEquals(false, $this->person->getIsVisible());
        $this->assertIsArray($this->person->getUpdatedFields());
        $this->assertCount(1,$this->person->getUpdatedFields());
        $this->assertArrayHasKey('isVisible',$this->person->getUpdatedFields());
    }
    
    public function test_setIsVisible_toBool_fromString() : void {
        //Act
        $this->person->setIsVisible("false");
        //Assert
        $this->assertEquals(false, $this->person->getIsVisible());
        $this->assertIsArray($this->person->getUpdatedFields());
        $this->assertCount(1,$this->person->getUpdatedFields());
        $this->assertArrayHasKey('isVisible',$this->person->getUpdatedFields());
    }
    
    public function test_setIsVisible_toBool_fromInt() : void {
        //Act
        $this->person->setIsVisible(0);
        //Assert
        $this->assertEquals(false, $this->person->getIsVisible());
        $this->assertIsArray($this->person->getUpdatedFields());
        $this->assertCount(1,$this->person->getUpdatedFields());
        $this->assertArrayHasKey('isVisible',$this->person->getUpdatedFields());
    }
    public function test_setIsValid_toBool() : void {
        //Act
        $this->person->setIsValid(false);
        //Assert
        $this->assertEquals(false, $this->person->getIsValid());
        $this->assertIsArray($this->person->getUpdatedFields());
        $this->assertCount(1,$this->person->getUpdatedFields());
        $this->assertArrayHasKey('isValid',$this->person->getUpdatedFields());
    }
    
    public function test_setIsValid_toBool_fromString() : void {
        //Act
        $this->person->setIsValid("false");
        //Assert
        $this->assertEquals(false, $this->person->getIsValid());
        $this->assertIsArray($this->person->getUpdatedFields());
        $this->assertCount(1,$this->person->getUpdatedFields());
        $this->assertArrayHasKey('isValid',$this->person->getUpdatedFields());
    }
    
    public function test_setIsValid_toBool_fromInt() : void {
        //Act
        $this->person->setIsValid(0);
        //Assert
        $this->assertEquals(false, $this->person->getIsValid());
        $this->assertIsArray($this->person->getUpdatedFields());
        $this->assertCount(1,$this->person->getUpdatedFields());
        $this->assertArrayHasKey('isValid',$this->person->getUpdatedFields());
    }
    
    /**
	* {@inheritDoc}
	*/
    public function tearDown(): void {
		parent::tearDown();
	}
}
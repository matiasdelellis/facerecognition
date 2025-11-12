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

use OCA\FaceRecognition\Db\Image;
use OCP\AppFramework\Db\Entity;

#[CoversClass(Image::class)]
class ImageTest extends TestCase {
    /** @var Image test instance*/
	private $image;

    /**
	* {@inheritDoc}
	*/
	public function setUp(): void {
        parent::setUp();
        $this->image = new Image();
        $this->image->setUser("user1");
        $this->image->setFile(101);
        $this->image->setModel(1);
        $this->image->setIsProcessed(true);
        $this->image->setError(null);
        $this->image->setLastProcessedTime(new DateTime('2024-01-01 10:00:00'));
        $this->image->setProcessingDuration(123);
        $this->image->resetUpdatedFields();
	}

    public function test_jsonSerialize() : void {
        //Act
        $json = $this->image->jsonSerialize();
        //Assert
        $this->assertNotNull($json);
        $this->assertIsArray($json);
        $this->assertCount(8,$json);
        $this->assertArrayHasKey('id',$json);
        $this->assertArrayHasKey('user',$json);
        $this->assertArrayHasKey('file',$json);
        $this->assertArrayHasKey('model',$json);
        $this->assertArrayHasKey('is_processed',$json);
        $this->assertArrayHasKey('error',$json);
        $this->assertArrayHasKey('last_processed_time',$json);
        $this->assertArrayHasKey('processing_duration',$json);
    }
    
    public function test_setIsProcessed_toBool() : void {
        //Act
        $this->image->setIsProcessed(false);
        //Assert
        $this->assertEquals(false, $this->image->getIsProcessed());
        $this->assertIsArray($this->image->getUpdatedFields());
        $this->assertCount(1,$this->image->getUpdatedFields());
        $this->assertArrayHasKey('isProcessed',$this->image->getUpdatedFields());
    }
    
    public function test_setIsProcessed_toBool_fromString() : void {
        //Act
        $this->image->setIsProcessed("false");
        //Assert
        $this->assertEquals(false, $this->image->getIsProcessed());
        $this->assertIsArray($this->image->getUpdatedFields());
        $this->assertCount(1,$this->image->getUpdatedFields());
        $this->assertArrayHasKey('isProcessed',$this->image->getUpdatedFields());
    }
    
    public function test_setIsProcessed_toBool_fromInt() : void {
        //Act
        $this->image->setIsProcessed(0);
        //Assert
        $this->assertEquals(false, $this->image->getIsProcessed());
        $this->assertIsArray($this->image->getUpdatedFields());
        $this->assertCount(1,$this->image->getUpdatedFields());
        $this->assertArrayHasKey('isProcessed',$this->image->getUpdatedFields());
    }

    /**
	* {@inheritDoc}
	*/
    public function tearDown(): void {
		parent::tearDown();
	}
}
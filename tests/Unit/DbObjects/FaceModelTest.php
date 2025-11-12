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

use OCA\FaceRecognition\Db\FaceModel;
use OCP\AppFramework\Db\Entity;

#[CoversClass(FaceModel::class)]
class FaceModelTest extends TestCase {
    /** @var FaceModel test instance*/
	private $faceModel;

    /**
	* {@inheritDoc}
	*/
	public function setUp(): void {
        parent::setUp();
        $this->faceModel = new FaceModel();
        $this->faceModel->setId(1);
        $this->faceModel->setName("Some dummy name");
        $this->faceModel->setDescription([0.34,0.234,0.123,0.543,0.654,0.765]);
        $this->faceModel->resetUpdatedFields();
	}

    public function test_jsonSerialize() : void {
        //Act
        $json = $this->faceModel->jsonSerialize();
        //Assert
        $this->assertNotNull($json);
        $this->assertIsArray($json);
        $this->assertCount(3,$json);
        $this->assertArrayHasKey('id',$json);
        $this->assertArrayHasKey('name',$json);
        $this->assertArrayHasKey('description',$json);
    }
    
    /**
	* {@inheritDoc}
	*/
    public function tearDown(): void {
		parent::tearDown();
	}
}
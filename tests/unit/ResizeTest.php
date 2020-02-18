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

use OCP\Image as OCP_Image;

use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;

use OCP\App\IAppManager;
use OCP\Files\IRootFolder;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Model\DlibCnn5Model;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

use Test\TestCase;

class ResizeTest extends TestCase {
	/** @var FaceRecognitionContext Context */
	private $context;

	/**
	 * {@inheritDoc}
	 */
	public function setUp() {
		$appManager = $this->createMock(IAppManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$config = $this->createMock(IConfig::class);
		$modelService = $this->createMock(ModelService::class);
		$this->context = new FaceRecognitionContext($appManager, $userManager, $rootFolder, $config, $modelService);

		$logger = $this->createMock(ILogger::class);
		$this->context->logger = new FaceRecognitionLogger($logger);
	}

	public function testResize() {
		$imageMapper = $this->createMock(ImageMapper::class);
		$fileService = $this->createMock(FileService::class);
		$settingsService = $this->createMock(SettingsService::class);
		$dlibCnn5Model = $this->container->query(DlibCnn5Model::class);
		$imageProcessingTask = new ImageProcessingTask($imageMapper, $fileService, $settingsService, $dlibCnn5Model);

		$imageProcessingTask->setContext($this->context);

		$image = new OCP_Image();

		// Try when there is no change
		$image->setResource(imagecreate(100, 100));
		$ratio = $imageProcessingTask->resizeImage($image, 100 * 100);
		$this->assertEquals(1, $ratio);
		$this->assertEquals(100, imagesx($image->resource()));
		$this->assertEquals(100, imagesy($image->resource()));
		// This image need double scaling up
		$image->setResource(imagecreate(100, 100));
		$ratio = $imageProcessingTask->resizeImage($image, 200 * 200);
		$this->assertEquals(1/2, $ratio);
		$this->assertEquals(200, imagesx($image->resource()));
		$this->assertEquals(200, imagesy($image->resource()));
		// This image need double scaling down
		$image->setResource(imagecreate(200, 200));
		$ratio = $imageProcessingTask->resizeImage($image, 100 * 100);
		$this->assertEquals(2, $ratio);
		$this->assertEquals(100, imagesx($image->resource()));
		$this->assertEquals(100, imagesy($image->resource()));
		// No change and ratio is different
		$image->setResource(imagecreate(200, 50));
		$ratio = $imageProcessingTask->resizeImage($image, 200 * 50);
		$this->assertEquals(1, $ratio);
		$this->assertEquals(200, imagesx($image->resource()));
		$this->assertEquals(50, imagesy($image->resource()));
		// Scaling up 3.5 times and ratio is different
		$image->setResource(imagecreate(200, 30));
		$ratio = $imageProcessingTask->resizeImage($image, 200 * 30 * 3.5 * 3.5);
		$this->assertEquals(1/3.5, $ratio);
		$this->assertEquals(200 * 3.5, imagesx($image->resource()));
		$this->assertEquals(30 * 3.5, imagesy($image->resource()));
		// Scaling down 4x
		$image->setResource(imagecreate(40, 300));
		$ratio = $imageProcessingTask->resizeImage($image, (40 * 300) / (4 * 4));
		$this->assertEquals(4, $ratio);
		$this->assertEquals(40 /4, imagesx($image->resource()));
		$this->assertEquals(300 / 4, imagesy($image->resource()));
	}
}
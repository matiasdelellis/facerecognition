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
namespace OCA\FaceRecognition\Tests\Unit;

use OCP\Files\File;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

use Psr\Log\LoggerInterface;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\ManualRegionMapper;
use OCA\FaceRecognition\Listener\PostWriteListener;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

use Test\TestCase;

/**
 * A new version of a photo drops the faces of the old one, and with them its
 * regions: what their search found belongs to the old version.
 */
class PostWriteListenerRegionsTest extends TestCase {

	private const IMAGE_ID = 42;

	/** @var ImageMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $imageMapper;

	/** @var ManualRegionMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $regionMapper;

	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	/** @var PostWriteListener */
	private $listener;

	public function setUp(): void {
		parent::setUp();

		$this->imageMapper = $this->createMock(ImageMapper::class);
		$this->regionMapper = $this->createMock(ManualRegionMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(true);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getCurrentFaceModel')->willReturn(1);
		$settingsService->method('getUserEnabled')->willReturn(true);
		$settingsService->method('isAllowedMimetype')->willReturn(true);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('isAllowedNode')->willReturn(true);
		$fileService->method('isUserFile')->willReturn(true);
		$fileService->method('isUnderNoDetection')->willReturn(false);

		$faceMapper = $this->createMock(FaceMapper::class);
		$faceMapper->method('findByImage')->willReturn([]);

		$this->listener = new PostWriteListener($this->logger, $userManager,
			$this->createMock(IUserSession::class), $faceMapper, $this->imageMapper,
			$this->createMock(ClusterMapper::class), $this->regionMapper, $settingsService, $fileService);
	}

	private function write(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$node = $this->createMock(File::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getId')->willReturn(500);
		$node->method('getName')->willReturn('photo.jpg');
		$node->method('getMimeType')->willReturn('image/jpeg');

		$this->listener->handle(new NodeWrittenEvent($node));
	}

	public function testANewVersionOfAPhotoDropsItsRegions() {
		$this->imageMapper->method('imageExists')->willReturn(self::IMAGE_ID);
		$this->regionMapper->method('isAvailable')->willReturn(true);
		$this->regionMapper->expects($this->once())->method('removeFromImage')->with(self::IMAGE_ID);

		$this->write();
	}

	public function testANewPhotoHasNoRegionsToDrop() {
		$this->imageMapper->method('imageExists')->willReturn(null);
		$this->regionMapper->expects($this->never())->method('removeFromImage');

		$this->write();
	}

	public function testBeforeTheMigrationThereIsNothingToDrop() {
		$this->imageMapper->method('imageExists')->willReturn(self::IMAGE_ID);
		$this->regionMapper->method('isAvailable')->willReturn(false);
		$this->regionMapper->expects($this->never())->method('removeFromImage');

		$this->write();
	}

	/**
	 * Saving the file must not fail because the regions could not be removed.
	 */
	public function testAFailureToDropTheRegionsDoesNotFailTheWrite() {
		$this->imageMapper->method('imageExists')->willReturn(self::IMAGE_ID);
		$this->regionMapper->method('isAvailable')->willReturn(true);
		$this->regionMapper->method('removeFromImage')->willThrowException(new \RuntimeException('deadlock'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('[manual faces] The regions of the previous version of photo.jpg could not be removed: deadlock'));

		$this->write();
	}
}

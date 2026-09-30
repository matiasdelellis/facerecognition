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
namespace OCA\FaceRecognition\Tests\Integration;

use OC\Files\View;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCA\FaceRecognition\BackgroundJob\Tasks\AddMissingImagesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\CreateClustersTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\EnumerateImagesMissingFacesTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ImageProcessingTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ManualFaceDescriptorTask;
use OCA\FaceRecognition\BackgroundJob\Tasks\ManualRegionTask;

use OCA\FaceRecognition\Db\ClusterMapper;
use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;
use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\ManualRegionMapper;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model;
use OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\ManualFaceService;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * What the integration tests of the faces and regions the user marks share:
 * photos made for them, and the tasks of the background job run one by one.
 *
 * The photos are made of lenna.jpg, 158 x 158 pixels, where the analysis finds
 * the face at 49,62 with 75 x 75 pixels. Scaled up, the face is big enough to
 * leave room between the size of what the user draws and the size of what the
 * detector finds.
 */
abstract class ManualFaceIntegrationTestCase extends IntegrationTestCase {

	/** @var int Model used through the tests */
	const MODEL_ID = ModelManager::DEFAULT_FACE_MODEL_ID;

	/** Where the analysis finds the face of lenna.jpg */
	const LENNA_FACE = ['x' => 49, 'y' => 62, 'width' => 75, 'height' => 75];

	/** @var array<string, string> App settings to restore after the test */
	private $savedSettings = [];

	public function setUp(): void {
		parent::setUp();

		// Small images, so that the tests are quick, and none skipped for its size.
		$this->setAppValue('min_image_size', '1');
		$this->setAppValue('max_image_area', (string) (200 * 200));

		$modelManager = $this->container->query(ModelManager::class);
		$modelManager->getModel(DlibHogModel::FACE_MODEL_ID)->install();
		$modelManager->getModel(DlibCnn5Model::FACE_MODEL_ID)->install();
		$this->container->query(SettingsService::class)->setCurrentFaceModel(self::MODEL_ID);
	}

	public function tearDown(): void {
		foreach ($this->savedSettings as $key => $value) {
			if (is_null($value)) {
				$this->config->deleteAppValue('facerecognition', $key);
			} else {
				$this->config->setAppValue('facerecognition', $key, $value);
			}
		}
		$this->savedSettings = [];

		parent::tearDown();
	}

	/**
	 * Sets an app setting for this test only.
	 */
	protected function setAppValue(string $key, string $value): void {
		if (!array_key_exists($key, $this->savedSettings)) {
			$keys = $this->config->getAppKeys('facerecognition');
			$this->savedSettings[$key] = in_array($key, $keys, true) ? $this->config->getAppValue('facerecognition', $key) : null;
		}
		$this->config->setAppValue('facerecognition', $key, $value);
	}

	// --- Photos -----------------------------------------------------------

	protected function lenna(): string {
		return file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/lenna.jpg');
	}

	protected function black(): string {
		return file_get_contents(\OC::$SERVERROOT . '/apps/facerecognition/tests/assets/black.jpg');
	}

	/** lenna.jpg scaled up $factor times: the face is at LENNA_FACE times $factor. */
	protected function lennaScaled(int $factor): string {
		$source = imagecreatefromstring($this->lenna());
		$scaled = imagescale($source, 158 * $factor, 158 * $factor);
		return $this->jpeg($scaled);
	}

	/** Two lenna.jpg scaled up $factor times, side by side: a photo with two faces. */
	protected function twoLennas(int $factor): string {
		$source = imagecreatefromstring($this->lenna());
		$size = 158 * $factor;
		$face = imagescale($source, $size, $size);
		$photo = imagecreatetruecolor(2 * $size, $size);
		imagecopy($photo, $face, 0, 0, 0, 0, $size, $size);
		imagecopy($photo, $face, $size, 0, 0, 0, $size, $size);
		return $this->jpeg($photo);
	}

	private function jpeg($image): string {
		ob_start();
		imagejpeg($image, null, 95);
		return ob_get_clean();
	}

	/**
	 * Puts a photo in the files of the user and lets the scan register it.
	 *
	 * @return Image the image of the photo
	 */
	protected function upload(string $name, string $data): Image {
		$this->loginAsUser($this->user->getUID());
		$view = new View('/' . $this->user->getUID() . '/files');
		$view->file_put_contents($name, $data);
		$fileId = $view->getFileInfo($name)->getId();

		$this->scanImages();

		$imageMapper = $this->container->query(ImageMapper::class);
		$found = $imageMapper->findFromFile($this->user->getUID(), self::MODEL_ID, $fileId);
		$this->assertNotNull($found, 'The scan must have registered ' . $name);
		// Neither findFromFile() nor find() reads every column, and the tests
		// need the file, the user and the model (resetImage() looks for the
		// image by them).
		$image = $imageMapper->find($this->user->getUID(), $found->getId());
		$image->setUser($this->user->getUID());
		$image->setModel(self::MODEL_ID);
		return $image;
	}

	/**
	 * An image of a file that does not exist, as if it was deleted without the
	 * app noticing.
	 */
	protected function imageOfAMissingFile(): Image {
		$image = new Image();
		$image->setUser($this->user->getUID());
		$image->setFile(2000000000 + rand(0, 100000));
		$image->setModel(self::MODEL_ID);
		$image->setIsProcessed(false);
		return $this->container->query(ImageMapper::class)->insert($image);
	}

	// --- Faces ------------------------------------------------------------

	/**
	 * A face drawn by hand that waits for its search, in pixels of the photo,
	 * in the given cluster or in none.
	 */
	protected function insertMarking(int $imageId, int $x, int $y, int $width, int $height, ?int $clusterId = null): Face {
		$face = new Face();
		$face->setImage($imageId);
		$face->cluster = $clusterId;
		$face->setX($x);
		$face->setY($y);
		$face->setWidth($width);
		$face->setHeight($height);
		$face->setConfidence(0.0);
		$face->landmarks = [];
		$face->descriptor = [];
		$face->isGroupable = true;
		$face->setCreationTime(new \DateTime());
		return $this->container->query(FaceMapper::class)->insertManualFace($face);
	}

	/**
	 * A face as the search of a region creates it, with a descriptor that is
	 * not the one of any real face.
	 */
	protected function insertRescanFace(int $imageId, array $box, ?array $descriptor = null): Face {
		$face = new Face();
		$face->image = $imageId;
		$face->x = $box['x'];
		$face->y = $box['y'];
		$face->width = $box['width'];
		$face->height = $box['height'];
		$face->confidence = 1.05;
		$face->landmarks = [['x' => $box['x'], 'y' => $box['y']]];
		$face->descriptor = $descriptor ?? self::descriptor(0.0);
		$face->setCreationTime(new \DateTime());
		return $this->container->query(FaceMapper::class)->insertRescanFace($face);
	}

	/**
	 * A face as the analysis creates it, on an image of its own, with the given
	 * descriptor, box and confidence.
	 */
	protected function insertDetectedFace(array $descriptor, int $size, float $confidence): Face {
		$image = new Image();
		$image->setUser($this->user->getUID());
		$image->setFile(1000000 + rand(0, 100000));
		$image->setModel(self::MODEL_ID);
		$image = $this->container->query(ImageMapper::class)->insert($image);

		$face = Face::fromModel($image->getId(), [
			'left' => 0, 'right' => $size, 'top' => 0, 'bottom' => $size,
			'detection_confidence' => $confidence,
			'landmarks' => [],
			'descriptor' => $descriptor,
		]);
		return $this->container->query(FaceMapper::class)->insertFace($face);
	}

	/**
	 * A descriptor that is not the one of any real face. The components must
	 * reach dlib as doubles, see CreateClustersTaskTest::DESCRIPTOR_BASE.
	 */
	protected static function descriptor(float $offset): array {
		return array_fill(0, 128, 0.5 + $offset);
	}

	/**
	 * A cluster of the given person, for faces to be put in.
	 */
	protected function clusterOf(string $name): int {
		$clusterMapper = $this->container->query(ClusterMapper::class);
		$person = $this->container->query(PersonMapper::class)->findOrCreateByName($this->user->getUID(), $name);
		$clusterId = $clusterMapper->create($this->user->getUID(), self::MODEL_ID);
		$clusterMapper->setPerson($clusterId, $person->getId());
		return $clusterId;
	}

	protected function nameOfCluster(?int $clusterId): ?string {
		if (is_null($clusterId)) {
			return null;
		}
		$cluster = $this->container->query(ClusterMapper::class)->find($this->user->getUID(), $clusterId);
		if (is_null($cluster->getPerson())) {
			return null;
		}
		return $this->container->query(PersonMapper::class)->find($this->user->getUID(), $cluster->getPerson())->getName();
	}

	/**
	 * The row of a face as it is stored, every column of it, or null if there
	 * is none.
	 */
	protected function row(int $faceId): ?array {
		$db = $this->container->query(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('*')
			->from('facerecog_faces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		$row['descriptor'] = json_decode(is_resource($row['descriptor']) ? stream_get_contents($row['descriptor']) : $row['descriptor'], true);
		$row['landmarks'] = json_decode(is_resource($row['landmarks']) ? stream_get_contents($row['landmarks']) : $row['landmarks'], true);
		$row['is_groupable'] = (bool) $row['is_groupable'];
		$row['is_manual'] = (bool) $row['is_manual'];
		$row['cluster'] = is_null($row['cluster']) ? null : (int) $row['cluster'];
		foreach (['x', 'y', 'width', 'height'] as $column) {
			$row[$column] = (int) $row[$column];
		}
		$row['confidence'] = (float) $row['confidence'];
		return $row;
	}

	/**
	 * Writes the given columns of a face as they are, to put it in a state the
	 * test needs.
	 */
	protected function setColumns(int $faceId, array $values): void {
		$db = $this->container->query(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('facerecog_faces');
		foreach ($values as $column => $value) {
			if (is_bool($value)) {
				$qb->set($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_BOOL));
			} elseif (is_array($value)) {
				$qb->set($column, $qb->createNamedParameter(json_encode($value)));
			} else {
				$qb->set($column, $qb->createNamedParameter($value));
			}
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return int[] the faces of an image */
	protected function faceIdsOf(int $imageId): array {
		return array_map(function (Face $face) {
			return (int) $face->getId();
		}, $this->container->query(FaceMapper::class)->findByImage($imageId));
	}

	// --- Tasks ------------------------------------------------------------

	protected function scanImages(): void {
		$this->config->setUserValue($this->user->getUID(), 'facerecognition', AddMissingImagesTask::FULL_IMAGE_SCAN_DONE_KEY, 'false');
		$task = new AddMissingImagesTask(
			$this->container->query(ImageMapper::class),
			$this->container->query(FileService::class),
			$this->container->query(SettingsService::class));
		$this->context->user = $this->user;
		$this->runTask($task);
	}

	protected function runDescriptorTask(): void {
		$this->context->user = $this->user;
		$this->runTask(new ManualFaceDescriptorTask(
			$this->container->query(FaceMapper::class),
			$this->container->query(FileService::class),
			$this->container->query(SettingsService::class),
			$this->container->query(ModelManager::class),
			$this->container->query('OCP\ITempManager'),
			$this->container->query(ManualFaceService::class)));
	}

	protected function runRegionTask(): void {
		$this->context->user = $this->user;
		$this->runTask(new ManualRegionTask(
			$this->container->query(FaceMapper::class),
			$this->container->query(ManualRegionMapper::class),
			$this->container->query(FileService::class),
			$this->container->query(SettingsService::class),
			$this->container->query(ModelManager::class),
			$this->container->query('OCP\ITempManager')));
	}

	protected function runClustering(): void {
		$this->context->user = $this->user;
		$this->runTask(new CreateClustersTask(
			$this->container->query(ClusterMapper::class),
			$this->container->query(PersonMapper::class),
			$this->container->query(ImageMapper::class),
			$this->container->query(FaceMapper::class),
			$this->container->query(SettingsService::class)));
	}

	/**
	 * Analyzes the photos of the user that are due in the given pass: the fast
	 * pass with the HOG model on a small image, or the refinement with the
	 * current model.
	 */
	protected function analyze(bool $fastPass): void {
		$imageMapper = $this->container->query(ImageMapper::class);
		$settingsService = $this->container->query(SettingsService::class);

		$this->context->user = $this->user;
		$this->context->propertyBag['run_mode'] = $fastPass ? 'fast-mode' : 'default-mode';

		if ($fastPass) {
			$this->context->propertyBag['images'] = $imageMapper->findImagesWithoutFaces($this->user, self::MODEL_ID);
		} else {
			$this->runTask(new EnumerateImagesMissingFacesTask($settingsService, $imageMapper));
		}

		$this->runTask(new ImageProcessingTask(
			$imageMapper,
			$this->container->query(FaceMapper::class),
			$this->container->query(FileService::class),
			$settingsService,
			$this->container->query(ModelManager::class),
			$this->container->query('OCP\Lock\ILockingProvider')));
	}

	private function runTask($task): void {
		$generator = $task->execute($this->context);
		if ($generator instanceof \Generator) {
			foreach ($generator as $_) {
			}
			$this->assertTrue($generator->getReturn());
		} else {
			$this->assertTrue($generator);
		}
	}
}

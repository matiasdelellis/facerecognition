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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

/**
 * Task that gets all images (from database) that don't yet have faces found (e.g. they are not processed).
 * Shuffles found images and outputs them to context->propertyBag.
 */
class EnumerateImagesMissingFacesTask extends FaceRecognitionBackgroundTask {

	/** @var SettingsService Settings service */
	private $settingsService;

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/** @var FileService */
	private $fileService;

	/**
	 * @param SettingsService $settingsService Settings service
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FileService $fileService File service
	 */
	public function __construct(SettingsService $settingsService,
	                            ImageMapper     $imageMapper,
	                            FileService     $fileService)
	{
		parent::__construct();
		$this->settingsService = $settingsService;
		$this->imageMapper     = $imageMapper;
		$this->fileService     = $fileService;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Find all images which don't have faces generated for them";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		$modelId = $this->settingsService->getCurrentFaceModel();

		// The fast pass only takes the images that were never processed. Any
		// other run also takes the ones that were analyzed in the fast pass and
		// still have to be refined, unless the refinement is disabled.
		if ($context->isRunningInFastMode() || !$this->settingsService->getRefinementEnabled()) {
			$images = $this->imageMapper->findImagesWithoutFaces($this->context->user, $modelId);
		} else {
			$images = $this->imageMapper->findImagesToProcess($this->context->user, $modelId);
		}
		yield;

		// When the run is restricted to a path, only take the images that are
		// under it.
		$images = $this->filterForPath($images);

		// When running in parallel as a worker, only take the share of the
		// images that this worker owns, so that each image is analyzed by
		// exactly one worker. Each worker shuffles its own share.
		$images = $this->filterForWorker($images);

		shuffle($images);
		$this->context->propertyBag['images'] = $images;

		return true;
	}

	/**
	 * When the run is restricted to a path, keeps only the images that are
	 * under it. Without a path all the images are kept.
	 *
	 * The path is resolved here, and not taken from the task that crawls the
	 * filesystem, because a worker of a parallel run only executes the
	 * analysis, and has to know its own share of the images anyway.
	 *
	 * @param Image[] $images Images to filter
	 *
	 * @return Image[] The images that are under the path of the run
	 */
	private function filterForPath(array $images): array {
		$path = $this->context->getPath();
		if (is_null($path) || is_null($this->context->user)) {
			return $images;
		}

		$userId = $this->context->user->getUID();
		$this->fileService->setupFS($userId);

		$pictures = $this->fileService->getPicturesFromPath($path, $userId);
		if (is_null($pictures)) {
			$this->logInfo(sprintf('The path %s does not exist for the user %s', $path, $userId));
			return array();
		}

		// The images are matched by file id, which is what relates a row of
		// the table to a file of the user.
		$fileIds = array();
		foreach ($pictures as $picture) {
			$fileIds[$picture->getId()] = true;
		}

		return array_values(array_filter($images, function (Image $image) use ($fileIds): bool {
			return isset($fileIds[$image->getFile()]);
		}));
	}

	/**
	 * When this task runs in parallel as a worker, keeps only the images that
	 * belong to this worker. The work is statically partitioned by the image
	 * id, in the same way previewgenerator partitions the previews by the file
	 * id. Without a worker configuration all the images are kept.
	 *
	 * The partition is done here and not in the query, because there is no
	 * portable way to write it: Oracle does not have the '%' operator, SQLite
	 * does not have the MOD() function, and the query builder does not expose
	 * modulo at all. Each worker therefore queries all the images and discards
	 * the ones that are not its own.
	 *
	 * @param Image[] $images Images to filter
	 *
	 * @return Image[] The images that this worker has to analyze
	 */
	private function filterForWorker(array $images): array {
		$workerIndex = $this->context->propertyBag['worker_index'] ?? null;
		$workerCount = $this->context->propertyBag['worker_count'] ?? null;
		if (is_null($workerIndex) || is_null($workerCount)) {
			return $images;
		}

		return array_values(array_filter($images, function (Image $image) use ($workerIndex, $workerCount): bool {
			return ($image->getId() % $workerCount) === $workerIndex;
		}));
	}
}
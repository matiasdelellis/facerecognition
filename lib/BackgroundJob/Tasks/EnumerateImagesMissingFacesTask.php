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

	/**
	 * @param SettingsService $settingsService Settings service
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(SettingsService $settingsService,
	                            ImageMapper     $imageMapper)
	{
		parent::__construct();
		$this->settingsService = $settingsService;
		$this->imageMapper     = $imageMapper;
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

		// When running in parallel as a worker, only take the share of the
		// images that this worker owns, so that each image is analyzed by
		// exactly one worker. Each worker shuffles its own share.
		$images = $this->filterForWorker($images);

		shuffle($images);
		$this->context->propertyBag['images'] = $images;

		return true;
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
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
		$images = $this->imageMapper->findImagesWithoutFaces(null, $modelId);
		yield;

		shuffle($images);
		$this->context->propertyBag['images'] = $images;
		return true;
	}
}
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

use OCP\IDBConnection;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

/**
 * Task that gets all images (from database) that don't yet have faces found (e.g. they are not processed).
 * Shuffles found images and outputs them to context->propertyBag.
 */
class EnumerateImagesMissingFacesTask extends FaceRecognitionBackgroundTask {
	/** @var IDBConnection DB connection*/
	protected $connection;

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/**
	 * @param IDBConnection $connection DB connection
	 * @param ImageMapper $imageMapper Image mapper
	 */
	public function __construct(IDBConnection $connection, ImageMapper $imageMapper) {
		parent::__construct();
		$this->connection = $connection;
		$this->imageMapper = $imageMapper;
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

		$images = $this->imageMapper->findImagesWithoutFaces($this->context->user);
		yield;

		shuffle($images);
		$this->context->propertyBag['images'] = $images;

		return true;
	}
}
<?php
/**
 * @copyright Copyright (c) 2022 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Helper;

use OCP\IUser;

use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Album\AlbumMapper;

use OCA\FaceRecognition\Service\SettingsService;

class PhotoAlbums {

	/** @var PersonMapper Person mapper*/
	private $personMapper;

	/** @var ImageMapper Image mapper*/
	private $imageMapper;

	/** @var AlbumMapper Album mapper */
	private $albumMapper;

	/** @var SettingsService Settings service*/
	private $settingsService;

	/**
	 * @param PersonMapper $personMapper
	 * @param ImageMapper $imageMapper
	 * @param AlbumMapper $albumMapper
	 * @param SettingsService $settingsService
	 */
	public function __construct(PersonMapper    $personMapper,
	                            ImageMapper     $imageMapper,
	                            AlbumMapper     $albumMapper,
	                            SettingsService $settingsService)
	{
		$this->personMapper    = $personMapper;
		$this->imageMapper     = $imageMapper;
		$this->albumMapper     = $albumMapper;
		$this->settingsService = $settingsService;
	}

	/**
	 * @return void
	 */
	public function syncUser (string $userId) {
		$modelId = $this->settingsService->getCurrentFaceModel();

		/* Get current albums and persons to sync */
		$personNames = $this->getPersonsNames($userId, $modelId);
		$albumNames = $this->albumMapper->getAll($userId);

		/* Create albums for new persons */
		$albumsToCreate = array_diff ($personNames, $albumNames);
		foreach ($albumsToCreate as $albumToCreate) {
			$this->albumMapper->create($userId, $albumToCreate);
		}

		/* Remove albums for old persons */
		$albumsToDelete = array_diff ($albumNames, $personNames);
		foreach ($albumsToDelete as $albumToDelete) {
			$albumId = $this->albumMapper->get($userId, $albumToDelete);
			$this->albumMapper->delete($albumId);
		}

		/* Find person's images and sync */
		foreach ($personNames as $albumName) {
			$albumId = $this->albumMapper->get($userId, $albumName);

			/* Get images within albums and person's to compare and sync */
			$albumImages = $this->albumMapper->getFiles($albumId);
			$personImages = $this->getPersonsImages($userId, $modelId, $albumName);

			/* Delete old photos. Maybe corrections. */
			$imagesToDelete = array_diff ($albumImages, $personImages);
			foreach ($imagesToAdd as $image) {
				$this->albumMapper->removeFile($albumId, $image);
			}

			/* Add new photos to the person's album */
			$imagesToAdd = array_diff ($personImages, $albumImages);
			foreach ($imagesToAdd as $image) {
				$this->albumMapper->addFile($albumId, $image, $userId);
			}
		}
	}

	private function getPersonsNames(string $userId, int $modelId): array {
		$distintNames = $this->personMapper->findDistinctNames($userId, $modelId);
		$names = [];
		foreach ($distintNames as $distintName) {
			$names[] = $distintName->getName();
		}
		return $names;
	}

	private function getPersonsImages(string $userId, int $modelId, string $personName): array {
		$personImages = $this->imageMapper->findFromPerson($userId, $modelId, $personName);
		$images = [];
		foreach ($personImages as $image) {
			$images[] = $image->getFile();
		}
		return array_unique($images);
	}

}

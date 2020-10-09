<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c)  2020 Li Xiangbin  <dassio@icloud.com>
 *
 * @author Li Xiangbin <dassio@icloud.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Search;

use OCP\Search\IProvider;
use OCP\IL10N;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\Files\IMimeTypeDetector;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Model\IModel;

/**
 * Provide search results from the 'facerecognition' app
 */
class PersonSearchProvider implements IProvider {

	/** @var PersonMapper personMapper */
	private $personMapper;

	/** @var ImageMapper imageMapper */
	private $imageMapper;

	/** @var SettingsService Settings service */
	private $settingsService;

	/** @var IL10N */
	private $l10n;

	/** @var IMimeTypeDetector */
	private $mimeTypeDetector;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IModel*/
	private $modelId;

	public function __construct(PersonMapper      $personMapper,
	                            ImageMapper       $imageMapper,
	                            SettingsService   $settingsService,
	                            IL10N             $l10n,
	                            ImimeTypeDetector $mimeTypeDetector,
	                            IURLGenerator     $urlGenerator,
	                            IRootFolder       $rootFolder) {
		$this->personMapper     = $personMapper;
		$this->imageMapper = $imageMapper;
		$this->settingsService = $settingsService;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->rootFolder = $rootFolder;
		$this->mimeTypeDetector = $mimeTypeDetector;
		$this->modelId =$this->settingsService->getCurrentFaceModel();
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'facerecognition';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('Face Recognition');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query) : SearchResult {
		$page = $query->getCursor() ?? 0;
		$limit = $query->getLimit();
		return SearchResult::paginated(
			$this->l10n->t('Face Recognition'),
			array_map(function (Person $result) {
				$personName = $result->getName();
				$link = '/index/settings/user/facerecognition?name=' . \OCP\Util::encodePath($personName);

				$image = $this->imageMapper->getPersonAvatar($result);
				$file = $this->rootFolder->getById($image->getFile())[0];
				$file = new \OC\Search\Result\File($file->getFileInfo());
				// Generate thumbnail url
				$thumbnailUrl = $file->has_preview
					? $this->urlGenerator->linkToRouteAbsolute('core.Preview.getPreviewByFileId', ['x' => 32, 'y' => 32, 'fileId' => $file->id])
					: '';

				return new SearchResultEntry(
				    $thumbnailUrl,
				    $personName,
				    '',
				    $this->urlGenerator->getAbsoluteURL($link),
				    $result->type === 'folder' ? 'icon-folder' : $this->mimeTypeDetector->mimeTypeIcon($file->mime_type)
				);
			},
			$this->personMapper->findPersonsLike($user->getUID(), 
			    $this->modelId, 
			    $query->getTerm(),
			    $page * $limit,
			    $limit)
			),
			$page);
	}

}

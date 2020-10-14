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
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use OCP\Files\IRootFolder;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;
use OCA\FaceRecognition\Db\ImageMapper;
use OCA\FaceRecognition\Service\SettingsService;
use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Service\UrlService;

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

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var UrlService */
	private $urlService;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var int*/
	private $modelId;

	public function __construct(PersonMapper      $personMapper,
	                            ImageMapper       $imageMapper,
	                            SettingsService   $settingsService,
	                            IL10N             $l10n,
	                            UrlService        $urlService,
	                            IURLGenerator     $urlGenerator,
	                            IRootFolder       $rootFolder) {
		$this->personMapper     = $personMapper;
		$this->imageMapper      = $imageMapper;
		$this->settingsService  = $settingsService;
		$this->l10n             = $l10n;
		$this->urlService       = $urlService;
		$this->urlGenerator     = $urlGenerator;
		$this->rootFolder       = $rootFolder;
		$this->modelId          = $this->settingsService->getCurrentFaceModel();
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
		if ($route === 'settings.PersonalSettings.index' && $routeParameters["section"] === 'facerecognition') {
			return 0;
		}
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
				return new SearchResultEntry(
				    $this->urlGenerator->imagePath('facerecognition','avatar.webp'),
				    $personName,
				    '',
				    $this->urlService->getRedirectToPersonUrl($personName),
				    '',
				    true,
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

<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
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

namespace OCA\FaceRecognition\Controller;

use OCP\IRequest;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Controller;

use OCA\FaceRecognition\Db\Person;
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Db\Relation;
use OCA\FaceRecognition\Db\RelationMapper;

use OCA\FaceRecognition\Service\SettingsService;

class RelationController extends Controller {

	/** @var PersonMapper */
	private $personMapper;

	/** @var RelationMapper */
	private $relationMapper;

	/** @var SettingsService */
	private $settingsService;

	/** @var string */
	private $userId;

	public function __construct($AppName,
	                            IRequest        $request,
	                            PersonMapper    $personMapper,
	                            RelationMapper  $relationMapper,
	                            SettingsService $settingsService,
	                            $UserId)
	{
		parent::__construct($AppName, $request);

		$this->personMapper    = $personMapper;
		$this->relationMapper  = $relationMapper;
		$this->settingsService = $settingsService;
		$this->userId          = $UserId;
	}

	/**
	 * @NoAdminRequired
	 * @param int $personId
	 */
	public function findByPerson(int $personId) {
		$deviation = $this->settingsService->getDeviation();

		$enabled = (version_compare(phpversion('pdlib'), '1.0.2', '>=') && ($deviation > 0.0));

		$resp = array();
		$resp['enabled'] = $enabled;
		$resp['proposed'] = array();

		if (!$enabled)
			return new DataResponse($resp);

		$mainPerson = $this->personMapper->find($this->userId, $personId);

		$proposed = array();
		$relations = $this->relationMapper->findFromPerson($this->userId, $personId, RELATION::PROPOSED);
		foreach ($relations as $relation) {
			$person1 = $this->personMapper->findFromFace($this->userId, $relation->getFace1());
			if (($person1->getId() !== $personId) && ($mainPerson->getName() !== $person1->getName())) {
				$proffer = array();
				$proffer['origId'] = $mainPerson->getId();
				$proffer['id'] = $person1->getId();
				$proffer['name'] = $person1->getName();
				$proposed[] = $proffer;
			}
			$person2 = $this->personMapper->findFromFace($this->userId, $relation->getFace2());
			if (($person2->getId() !== $personId) && ($mainPerson->getName() !== $person2->getName())) {
				$proffer = array();
				$proffer['origId'] = $mainPerson->getId();
				$proffer['id'] = $person2->getId();
				$proffer['name'] = $person2->getName();
				$proposed[] = $proffer;
			}
		}
		$resp['proposed'] = $proposed;

		return new DataResponse($resp);
	}

	/**
	 * @NoAdminRequired
	 * @param int $personId
	 * @param int $toPersonId
	 * @param int $state
	 */
	public function updateByPersons(int $personId, int $toPersonId, int $state) {
		$relations = $this->relationMapper->findFromPersons($personId, $toPersonId);

		foreach ($relations as $relation) {
			$relation->setState($state);
			$this->relationMapper->update($relation);
		}

		if ($state === RELATION::ACCEPTED) {
			$person = $this->personMapper->find($this->userId, $personId);
			$name = $person->getName();

			$toPerson = $this->personMapper->find($this->userId, $toPersonId);
			$toPerson->setName($name);
			$this->personMapper->update($toPerson);
		}

		$relations = $this->relationMapper->findFromPersons($personId, $toPersonId);
		return new DataResponse($relations);
	}

}

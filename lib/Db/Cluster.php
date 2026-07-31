<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
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
namespace OCA\FaceRecognition\Db;

use JsonSerializable;

use OCP\AppFramework\Db\Entity;

/**
 * A set of faces of one model that look alike.
 *
 * It is not a person: the same person at another age, from another angle or
 * without the beard ends up in another cluster, because the descriptors of
 * those faces are farther apart than the sensitivity and joining them on that
 * evidence is how two people end up together. Which person a cluster is one of
 * the looks of is a decision of the user, and that is the person column.
 *
 * @method string getUser()
 * @method void setUser(string $user)
 * @method int getModel()
 * @method void setModel(int $model)
 * @method int|null getPerson()
 * @method bool getIsVisible()
 */
class Cluster extends Entity implements JsonSerializable {
	/**
	 * User this cluster belongs to
	 *
	 * @var string
	 */
	protected $user;

	/**
	 * Model the faces of this cluster were obtained with
	 *
	 * @var int
	 */
	protected $model;

	/**
	 * Person this cluster is one of the looks of, if the user said so
	 *
	 * @var int|null
	 */
	protected $person;

	/**
	 * Whether this cluster is relevant to the user, who can ignore it
	 *
	 * @var bool
	 */
	protected $isVisible;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('user', 'string');
		$this->addType('model', 'integer');
		$this->addType('person', 'integer');
		$this->addType('isVisible', 'bool');
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user' => $this->user,
			'model' => $this->model,
			'person' => $this->person,
			'is_visible' => $this->isVisible,
		];
	}

	/**
	 * @param int|null $person
	 */
	public function setPerson($person): void {
		$this->person = is_null($person) ? null : (int) $person;
		$this->markFieldUpdated('person');
	}

	public function setIsVisible($isVisible): void {
		if (is_bool($isVisible)) {
			$this->isVisible = $isVisible;
		} else {
			$this->isVisible = filter_var($isVisible, FILTER_VALIDATE_BOOLEAN);
		}
		$this->markFieldUpdated('isVisible');
	}
}

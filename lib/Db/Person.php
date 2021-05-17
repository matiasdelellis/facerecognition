<?php
/**
 * @copyright Copyright (c) 2020-2021, Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Db;

use JsonSerializable;

use OCP\AppFramework\Db\Entity;

/**
 * Person represent one cluster, set of faces. It belongs to $user_id.
 *
 * @method string getName()
 * @method void setName(string $name)
 */
class Person extends Entity implements JsonSerializable {
	/**
	 * User this person belongs to
	 *
	 * @var string
	 * */
	protected $user;

	/**
	 * Name for this person/cluster. Must exists, even if linked user is set.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Whether this person is still valid
	 *
	 * @var bool
	 */
	protected $isValid;

	/**
	 * Last timestamp when this person/cluster was created, or when it was refreshed
	 *
	 * @var \DateTime|null
	 */
	protected $lastGenerationTime;

	/**
	 * Foreign key to other user that this person belongs to (if it is on same Nextcloud instance).
	 * It is set by owner of this cluster. It is optional.
	 *
	 * @var string|null
	*/
	protected $linkedUser;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('user', 'string');
		$this->addType('isValid', 'bool');
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user' => $this->user,
			'name' => $this->name,
			'is_valid' => $this->isValid,
			'last_generation_time' => $this->lastGenerationTime,
			'linked_user' => $this->linkedUser
		];
	}

	public function setIsValid($isValid) {
		if (is_bool($isValid)) {
			$this->isValid = $isValid;
		} else {
			$this->isValid = filter_var($isValid, FILTER_VALIDATE_BOOLEAN);
		}
		$this->markFieldUpdated('isValid');
	}
}

<?php
/**
 * @copyright Copyright (c) 2018-2026, Matias De lellis <mati86dl@gmail.com>
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
 * Somebody the user named.
 *
 * A person has as many clusters as different ways their face was found: another
 * age, another angle, with and without a beard. Which clusters those are is
 * something only the user can say, and saying it once holds for a whole
 * cluster.
 *
 * @method string getUser()
 * @method void setUser(string $user)
 * @method string getName()
 * @method void setName(string $name)
 */
class Person extends Entity implements JsonSerializable {
	/**
	 * User this person belongs to
	 *
	 * @var string
	 */
	protected $user;

	/**
	 * Name the user gave them. Two people of one user can be called the same.
	 *
	 * @var string
	 */
	protected $name;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('user', 'string');
		$this->addType('name', 'string');
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user' => $this->user,
			'name' => $this->name,
		];
	}
}

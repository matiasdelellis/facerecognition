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
namespace OCA\FaceRecognition\Db;

use JsonSerializable;

use OCP\AppFramework\Db\Entity;

/**
 * Person represent one cluster, set of faces. It belongs to $user_id.
 */
class Person extends Entity implements JsonSerializable {
	/** 
	 * User this person belongs to
	 * 
	 * @var string
	 * */
	protected $user_id;

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
	protected $is_valid;
	
	/**
	 * Last timestamp when this person/cluster was created, or when it was refreshed
	 *
	 * @var timestamp|null
	 */
	protected $last_generation_time;

	/**
	 * Foreign key to other user that this person belongs to (if it is on same Nextcloud instance).
	 * It is set by owner of this cluster. It is optional.
	 *
	 * @var string|null
	*/
	protected $linked_user_id;

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user_id' => $this->user_id,
			'name' => $this->name,
			'is_valid' => $this->is_valid,
			'last_generation_time' => $this->last_generation_time,
			'linked_user_id' => $this->linked_user_id
		];
	}
}

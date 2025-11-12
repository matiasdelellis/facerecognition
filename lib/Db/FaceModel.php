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
 * Represent one possible DNN model that faces can be detected, extracted and face descriptors taken.
 * Logically encapsulates files from one DNN model used to do stuff from above.
 */
class FaceModel extends Entity implements JsonSerializable
{

	protected $uid;
	protected $name;
	protected $description;

	public function jsonSerialize() : mixed{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description
		];
	}
}

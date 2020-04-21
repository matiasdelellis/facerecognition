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
namespace OCA\FaceRecognition\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Relation represents one relation beetwen two faces
 *
 * @method int getFace1()
 * @method int getFace2()
 * @method int getState()
 * @method void setFace1(int $face1)
 * @method void setFace2(int $face2)
 * @method void setState(int $state)
 */
class Relation extends Entity {

	/**
	 * Possible values of the state of a face relation
	 */
	public const PROPOSED = 0;
	public const ACCEPTED = 1;
	public const REJECTED = 2;

	/**
	 * Face id of a face of a person related with $face2
	 *
	 * @var int
	 * */
	protected $face1;

	/**
	 * Face id of a face of a person related with $face1
	 *
	 * @var int
	 * */
	protected $face2;

	/**
	 * State of two face relation. These are proposed, and can be accepted
	 * as as the same person, or rejected.
	 *
	 * @var int
	 * */
	protected $state;

}
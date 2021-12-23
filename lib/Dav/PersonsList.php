<?php
/**
 * @copyright Copyright (c) 2021 Matias De lellis <mati86dl@gmai.com>
 *
 * @author Matias De lellis <mati86dl@gmai.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\FaceRecognition\Dav;

use OCA\FaceRecognition\AppInfo\Application;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;

/**
 * Persons property
 *
 * This property encodes the list of 'persons' property.
 */
class PersonsList implements XmlSerializable {

	/**
	 * The list of persons
	 *
	 * @var array
	 */
	protected $persons;

	/**
	 * Creates the property.
	 *
	 * Persons is an array. Each element of the array has the following
	 * properties:
	 *
	 *   * name - Optional, name of person.
	 *   * id - Optional, id of person cluster.
	 *   * count - Optional count of images of that person
	 *   * top - Optional top position of the face in some image
	 *   * left - Optional left position of the face in some image.
	 *   * width - Optional width of the face in some image
	 *   * height - Optional height of the face in some image.
	 *
	 * @param array $persons
	 */
	public function __construct(array $persons) {
		$this->persons = $persons;
	}

	/**
	 * Returns the list of person, as it was passed to the constructor.
	 *
	 * @return array
	 */
	public function getValue() {
		return $this->persons;
	}

	/**
	 * The xmlSerialize metod is called during xml writing.
	 *
	 * Use the $writer argument to write its own xml serialization.
	 *
	 * An important note: do _not_ create a parent element. Any element
	 * implementing XmlSerializble should only ever write what's considered
	 * its 'inner xml'.
	 *
	 * The parent of the current element is responsible for writing a
	 * containing element.
	 *
	 * This allows serializers to be re-used for different element names.
	 *
	 * If you are opening new elements, you must also close them again.
	 *
	 * @param Writer $writer
	 * @return void
	 */
	public function xmlSerialize(Writer $writer) {
		$cs = '{' . Application::DAV_NS_FACE_RECOGNITION . '}';

		foreach ($this->persons as $person) {
			$writer->startElement($cs . 'person');
			if (isset($person['id'])) {
				$writer->writeElement($cs . 'id', $person['id']);
			}
			if (isset($person['name'])) {
				$writer->writeElement($cs . 'name', $person['name']);
			}
			if (isset($person['count'])) {
				$writer->writeElement($cs . 'count', $person['count']);
			}
			if (isset($person['top'])) {
				$writer->writeElement($cs . 'top', $person['top']);
			}
			if (isset($person['left'])) {
				$writer->writeElement($cs . 'left', $person['left']);
			}
			if (isset($person['width'])) {
				$writer->writeElement($cs . 'width', $person['width']);
			}
			if (isset($person['height'])) {
				$writer->writeElement($cs . 'height', $person['height']);
			}
			$writer->endElement();
		}
	}

}

<?php
/**
 * Face Recognition
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE.md file.
 *
 * @copyright 2017 Matias De lellis <mati86dl@delellis.com>
 */

namespace OCA\FaceRecognition\Search;

/**
 * A found file
 */
class Result extends \OCP\Search\Result {

	/**
	 * Type name; translated in templates
	 * @var string
	 */
	public $type = 'facecognition';

	/**
	 * Create a new file search result
	 * @param array $data file data given by provider
	 */

	public function __construct(array $data = null) {
		if($data !== null){
			$this->id = $data['id'];
			$this->name = $data['description'];
			$this->link = $data['link'];
			$this->icon = $data['icon'];
		}
	}
}

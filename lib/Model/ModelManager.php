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

namespace OCA\FaceRecognition\Model;

use OCP\IConfig;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

use OCA\FaceRecognition\Model\DlibCnnModel;

use OCA\FaceRecognition\Model\DlibCnn68Model;
use OCA\FaceRecognition\Model\DlibCnn5Model;

class ModelManager {

	/** @var DlibCnn5Model */
	private $dlibCnn5Model;

	/** @var DlibCnn68Model */
	private $dlibCnn68Model;

	/**
	 * @param DlibCnn5Model $model
	 * @param DlibCnn68Model $model
	 */
	public function __construct(DlibCnn5Model  $dlibCnn5Model,
	                            DlibCnn68Model $dlibCnn68Model)
	{
		$this->dlibCnn5Model  = $dlibCnn5Model;
		$this->dlibCnn68Model = $dlibCnn68Model;
	}

	/**
	 * @return DlibCnnModel
	 */
	public function getModel(int $version): DlibCnnModel {
		switch ($version) {
			case 1:
				$model = $this->dlibCnn5Model;
				break;
			case 2:
				$model = $this->dlibCnn68Model;
				break;
			default:
				$model = null;
				break;
		}
		return $model;
	}

}
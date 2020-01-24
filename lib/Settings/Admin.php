<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018-2020 Matias De lellis <mati86dl@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IL10N;

use OCA\FaceRecognition\Helper\Requirements;

use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

class Admin implements ISettings {

	/** @var ModelService */
	public $modelService;

	/** @var SettingsService */
	public $settingsService;

	/** @var IL10N */
	protected $l;

	public function __construct(ModelService    $modelService,
	                            SettingsService $settingsService,
	                            IL10N           $l)
	{
		$this->modelService    = $modelService;
		$this->settingsService = $settingsService;
		$this->l               = $l;
	}

	public function getPriority() {
		return 20;
	}

	public function getSection() {
		return 'facerecognition';
	}

	public function getForm() {

		$pdlibLoaded = TRUE;
		$pdlibVersion = '0.0';
		$modelVersion = $this->settingsService->getCurrentFaceModel();
		$modelPresent = TRUE;
		$resume = "";

		$req = new Requirements($this->modelService, $modelVersion);

		if ($req->pdlibLoaded()) {
			$pdlibVersion = $req->pdlibVersion();
		}
		else {
			$resume .= 'The PHP extension PDlib is not loaded. Please configure this. ';
			$pdlibLoaded = FALSE;
		}

		if (!$req->modelFilesPresent()) {
			$resume .= 'The files of the models version ' . $modelVersion . ' were not found. ';
			$modelPresent = FALSE;
		}

		$params = [
			'pdlib-loaded' => $pdlibLoaded,
			'pdlib-version' => $pdlibVersion,
			'model-version' => $modelVersion,
			'model-present' => $modelPresent,
			'resume' => $resume,
		];

		return new TemplateResponse('facerecognition', 'settings/admin', $params, '');

	}

}
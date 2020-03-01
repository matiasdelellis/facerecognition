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
use OCP\IL10N;
use OCP\Settings\ISettings;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\SettingsService;

class Admin implements ISettings {

	/** @var ModelManager */
	public $modelManager;

	/** @var SettingsService */
	public $settingsService;

	/** @var IL10N */
	protected $l;

	public function __construct(ModelManager    $modelManager,
	                            SettingsService $settingsService,
	                            IL10N           $l)
	{
		$this->modelManager    = $modelManager;
		$this->settingsService = $settingsService;
		$this->l10n            = $l10n;
	}

	public function getPriority() {
		return 20;
	}

	public function getSection() {
		return 'facerecognition';
	}

	public function getForm() {

		$meetDependencies = TRUE;
		$modelVersion = $this->settingsService->getCurrentFaceModel();
		$resume = "";

		$model = $this->modelManager->getModel($modelVersion);

		if (!$model) {
			$resume = $this->l10n->t("It seems you don't have any model installed.");
			// TODO: Document models and add link here.
		}

		if (!$model->meetDependencies()) {
			$resume .= $this->l10n->t("It seems that you do not meet the dependencies to use the current model.");
			$meetDependencies = FALSE;
		}

		$params = [
			'meet-dependencies' => $meetDependencies,
			'model-version' => $modelVersion,
			'resume' => $resume,
		];

		return new TemplateResponse('facerecognition', 'settings/admin', $params, '');

	}

}
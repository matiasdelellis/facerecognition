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
use OCP\Util as OCP_Util;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\SettingsService;

class Admin implements ISettings {

	/** @var ModelManager */
	public $modelManager;

	/** @var SettingsService */
	public $settingsService;

	/** @var MemoryLimits */
	public $memoryLimits;

	/** @var IL10N */
	protected $l10n;

	public function __construct(ModelManager    $modelManager,
	                            SettingsService $settingsService,
	                            MemoryLimits    $memoryLimits,
	                            IL10N           $l10n)
	{
		$this->modelManager    = $modelManager;
		$this->settingsService = $settingsService;
		$this->memoryLimits    = $memoryLimits;
		$this->l10n            = $l10n;
	}

	public function getPriority() {
		return 20;
	}

	public function getSection() {
		return 'facerecognition';
	}

	public function getForm() {

		$isConfigured = true;
		$maxImageRange = "8294400";
		$resume = '';
		$modelId = ModelManager::DEFAULT_FACE_MODEL_ID;

		$model = $this->modelManager->getCurrentModel();
		if (!is_null($model)) {
			$maxImageRange = strval($model->getMaximumArea());
			$modelId = $model->getId();
		} else {
			$resume .= $this->l10n->t("It seems you don't have any model installed.");
			$isConfigured = false;
		}

		$assignedMemory = $this->settingsService->getAssignedMemory();
		if ($modelId != 5 && $assignedMemory === SettingsService::DEFAULT_ASSIGNED_MEMORY) {
			$resume = $this->l10n->t("Seems that you still have to configure the assigned memory for image processing.");
			$isConfigured = false;
		}

		$params = [
			'is-configured' => $isConfigured,
			'model-version' => $modelId === ModelManager::DEFAULT_FACE_MODEL_ID ? $this->l10n->t("Not installed") : $modelId,
			'assigned-memory' => $assignedMemory > 0 ? OCP_Util::humanFileSize($assignedMemory) : $this->l10n->t("Not configured."),
			'max-image-range' => $maxImageRange,
			'resume' => $resume,
		];

		return new TemplateResponse('facerecognition', 'settings/admin', $params, '');

	}

}

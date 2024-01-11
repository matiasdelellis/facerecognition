<?php
/**
 * @copyright Copyright (c) 2019-2020 Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\BackgroundJob\Tasks;

use OCP\IUser;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionBackgroundTask;
use OCA\FaceRecognition\BackgroundJob\FaceRecognitionContext;

use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Service\FaceManagementService;
use OCA\FaceRecognition\Service\SettingsService;

/**
 * Task that, for each user, check if disabled the analysis,
 * and if necessary remove data from this application
 */
class DisabledUserRemovalTask extends FaceRecognitionBackgroundTask {

	/** @var ImageMapper Image mapper */
	private $imageMapper;

	/** @var FaceManagementService */
	private $faceManagementService;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param ImageMapper $imageMapper Image mapper
	 * @param FaceManagementService $faceManagementService
	 * @param SettingsService $settingsService
	 */
	public function __construct (ImageMapper           $imageMapper,
	                             FaceManagementService $faceManagementService,
	                             SettingsService       $settingsService)
	{
		parent::__construct();

		$this->imageMapper           = $imageMapper;
		$this->faceManagementService = $faceManagementService;
		$this->settingsService       = $settingsService;
	}

	/**
	 * @inheritdoc
	 */
	public function description() {
		return "Purge all the information of a user when disable the analysis.";
	}

	/**
	 * @inheritdoc
	 */
	public function execute(FaceRecognitionContext $context) {
		$this->setContext($context);

		// Check if we are called for one user only, or for all user in instance.
		$eligable_users = $this->context->getEligibleUsers();

		// Reset user datas if needed.
		foreach($eligable_users as $userId) {
			$userEnabled = $this->settingsService->getUserEnabled($userId);
			$imageCount = $this->imageMapper->countUserImages($userId, $this->settingsService->getCurrentFaceModel());
			if (!$userEnabled && $imageCount > 0) {
				// TODO: Check that the user really has information to remove.
				$this->logInfo(sprintf('Removing data from user %s that disable analysis', $userId));
				$this->faceManagementService->resetAllForUser($userId);
			}
			yield;
		}

		return true;
	}

}

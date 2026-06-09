<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2019, Branko Kokanovic <branko@kokanovic.org>
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
namespace OCA\FaceRecognition\Command;

use OCP\IUserManager;
use OCP\IUser;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\Helper\ProgressBar;

use OCA\FaceRecognition\Db\Face;
use OCA\FaceRecognition\Db\FaceMapper;

use OCA\FaceRecognition\Db\Image;
use OCA\FaceRecognition\Db\ImageMapper;

use OCA\FaceRecognition\Model\IModel;
use OCA\FaceRecognition\Model\ModelManager;

use OCA\FaceRecognition\Service\FaceManagementService;
use OCA\FaceRecognition\Service\FileService;

use OCA\FaceRecognition\Helper\CommandLock;

use OCP\Image as OCP_Image;

class MigrateCommand extends Command {

	/** @var FaceManagementService */
	protected $faceManagementService;

	/** @var FileService */
	protected $fileService;

	/** @var IUserManager */
	protected $userManager;

	/** @var ModelManager */
	protected $modelManager;

	/** @var FaceMapper */
	protected $faceMapper;

	/** @var ImageMapper Image mapper*/
	protected $imageMapper;

	/** @var OutputInterface $output */
	protected $output;

	/**
	 * @param FaceManagementService $faceManagementService
	 * @param IUserManager $userManager
	 */
	public function __construct(FaceManagementService $faceManagementService,
	                            FileService           $fileService,
	                            IUserManager          $userManager,
	                            ModelManager          $modelManager,
	                            FaceMapper            $faceMapper,
	                            ImageMapper           $imageMapper)
	{
		parent::__construct();

		$this->faceManagementService = $faceManagementService;
		$this->fileService           = $fileService;
		$this->userManager           = $userManager;
		$this->modelManager          = $modelManager;
		$this->faceMapper            = $faceMapper;
		$this->imageMapper           = $imageMapper;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:migrate')
			->setDescription(
				'Migrate the faces found in a model and analyze with the current model.')
			->addOption(
				'model_id',
				'm',
				InputOption::VALUE_REQUIRED,
				'The identifier number of the model to migrate',
				null
			)
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Migrate data for a given user only. If not given, migrate everything for all users.',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 *
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->output = $output;

		/**
		 * Check the considerations of the models to migrate.
		 */
		$modelId = $input->getOption('model_id');
		if (is_null($modelId)) {
			$output->writeln("You must indicate the ID of the model to migrate");
			return 1;
		}

		$model = $this->modelManager->getModel($modelId);
		if (is_null($model)) {
			$output->writeln("Invalid model Id");
			return 1;
		}

		if (!$model->isInstalled()) {
			$output->writeln("The model <$modelId> is not installed");
			return 1;
		}

		$currentModel = $this->modelManager->getCurrentModel();
		$currentModelId = (!is_null($currentModel)) ? $currentModel->getId() : -1;

		if ($currentModelId === $modelId) {
			$output->writeln("The proposed model <$modelId> to migrate must be other than the current one <$currentModelId>");
			return 1;
		}

		/**
		 * Check the user if it is provided.
		 */
		$userId = $input->getOption('user_id');
		if ($userId !== null) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$output->writeln("User with id <$userId> is unknown.");
				return 1;
			}
		}

		/**
		 * Get user to migrate
		 */
		$userIds = $this->getEligiblesUserId($userId);

		/**
		 * Check that any user have data in the current model
		 */
		foreach ($userIds as $mUserId) {
			if ($this->faceManagementService->hasDataForUser($mUserId, $currentModelId)) {
				$output->writeln("The user <$mUserId> in current model <$currentModelId> already has data. You cannot migrate to a used model.");
				return 1;
			}
		}

		// Get lock to avoid potential errors.
		//
		$lock = CommandLock::Lock("face:migrate");
		if (!$lock) {
			$output->writeln("Another command ('". CommandLock::IsLockedBy().  "') is already running that prevents it from continuing.");
			return 1;
		}

		/**
		 * Open the model and migrate to users.
		 */
		$currentModel->open();

		foreach ($userIds as $mUserId) {
			$this->migrateUser($currentModel, $modelId, $mUserId);
		}

		$output->writeln("The faces migration is done. Remember that you must recreate the clusters with the background_job command");

		// Release obtained lock
		//
		CommandLock::Unlock($lock);

		return 0;
	}

	/**
	 * @return void
	 */
	private function migrateUser(IModel $currentModel, int $oldModelId, $userId) {
		if (!$this->faceManagementService->hasDataForUser($userId, $oldModelId)) {
			$this->output->writeln("User <$userId> has no data in model <$oldModelId> to migrate.");
			return;
		}

		$this->output->writeln("Will be migrated <$userId> from model <$oldModelId>");

		$currentModelId = $currentModel->getId();
		$oldImages = $this->imageMapper->findAll($userId, $oldModelId);

		$progressBar = new ProgressBar($this->output, count($oldImages));
		$progressBar->start();

		foreach ($oldImages as $oldImage) {
			$newImage = $this->migrateImage($oldImage, $userId, $currentModelId);
			$oldFaces = $this->faceMapper->findFromFile($userId, $oldModelId, $newImage->getFile());
			if (count($oldFaces) > 0) {
				$filePath = $this->getImageFilePath($newImage);
				if ($filePath === null)
					continue;

				foreach ($oldFaces as $oldFace) {
					$this->migrateFace($currentModel, $oldFace, $newImage, $filePath);
				}
				$this->fileService->clean();
			}
			$progressBar->advance(1);
		}

		$progressBar->finish();

		$this->output->writeln("Done");
	}

	private function migrateImage($oldImage, string $userId, int $modelId): Image {
		$image = new Image();

		$image->setUser($userId);
		$image->setFile($oldImage->getFile());
		$image->setModel($modelId);
		$image->setIsProcessed($oldImage->getIsProcessed());
		$image->setError($oldImage->getError());
		$image->setLastProcessedTime($oldImage->getLastProcessedTime());
		$image->setProcessingDuration($oldImage->getProcessingDuration());

		return $this->imageMapper->insert($image);
	}

	/**
	 * @param string $filePath
	 */
	private function migrateFace(IModel $model, Face $oldFace, Image $image, string $filePath): void {
		// Get the rectangle and the confidence of the original face.
		$faceRect = $this->getFaceRect($oldFace);

		// Get the landmarks and descriptor with the new model.
		$faceAssoc = $model->compute($filePath, $faceRect);

		// Convert the assoc array into an Face object and insert on database.
		$face = Face::fromModel($image->getId(), $faceAssoc);
		$this->faceMapper->insertFace($face);
	}

	private function getImageFilePath(Image $image): ?string {
		$file = $this->fileService->getFileById($image->getFile(), $image->getUser());
		if (empty($file)) {
			return null;
		}

		$localPath = $this->fileService->getLocalFile($file);

		$image = new OCP_Image();
		$image->loadFromFile($localPath);
		if ($image->getOrientation() > 1) {
			$tempPath = $this->fileService->getTemporaryFile();
			$image->fixOrientation();
			$image->save($tempPath, 'image/png');
			return $tempPath;
		}

		return $localPath;
	}

	private function getFaceRect(Face $face): array {
		$rect = [];
		$rect['left']   = (int)$face->getX();
		$rect['right']  = (int)$face->getX() + $face->getWidth();
		$rect['top']    = (int)$face->getY();
		$rect['bottom'] = (int)$face->getY() + $face->getHeight();
		$rect['detection_confidence'] = $face->getConfidence();
		return $rect;
	}

	/**
	 * Get an array with the eligibles users taking into account the user argument,
	 * or all users.
	 */
	private function getEligiblesUserId(?string $userId = null): array {
		$eligible_users = array();
		if (is_null($userId)) {
			$this->userManager->callForAllUsers(function (IUser $user) use (&$eligible_users) {
				$eligible_users[] = $user->getUID();
			});
		} else {
			$eligible_users[] = $userId;
		}
		return $eligible_users;
	}

}

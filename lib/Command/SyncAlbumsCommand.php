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
namespace OCA\FaceRecognition\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\App\IAppManager;

use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\Helper\PhotoAlbums;

use OCA\FaceRecognition\Service\SettingsService;

class SyncAlbumsCommand extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var IAppManager */
	private $appManager;

	/** @var PhotoAlbums */
	protected $photoAlbums;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param IUserManager $userManager
	 * @param PhotoAlbums $photoAlbums
	 * @param SettingsService $settingsService
	 * @param IAppManager $appManager
	 */
	public function __construct(IUserManager    $userManager,
	                            IAppManager     $appManager,
	                            PhotoAlbums     $photoAlbums,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->appManager      = $appManager;
		$this->userManager     = $userManager;
		$this->photoAlbums     = $photoAlbums;
		$this->settingsService = $settingsService;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this
			->setName('face:sync-albums')
			->setDescription('Synchronize the people found with the photo albums')
			->addOption(
				'user_id',
				'u',
				InputOption::VALUE_REQUIRED,
				'Sync albums for a given user only. If not given, sync albums for all users.',
				null
			);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		if (!$this->appManager->isEnabledForUser('photos')) {
			$output->writeln('The photos app is disabled.');
			return 1;
		}

		$users = array();
		$userId = $input->getOption('user_id');
		if (!is_null($userId)) {
			if ($this->userManager->get($userId) === null) {
				$output->writeln("User with id <$userId> in unknown.");
				return 1;
			}
			else {
				$users[] = $userId;
			}
		}
		else {
			$this->userManager->callForAllUsers(function (IUser $iUser) use (&$users)  {
				$users[] = $iUser->getUID();
			});
		}

		foreach ($users as $user) {
			$output->write("Synchronizing albums for the user <$userId>. ");
			$this->photoAlbums->syncUser($userId);
			$output->writeln("Done.");
		}

		return 0;
	}

}

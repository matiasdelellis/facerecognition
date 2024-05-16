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
use OCA\FaceRecognition\Db\PersonMapper;

use OCA\FaceRecognition\Service\SettingsService;

class SyncAlbumsCommand extends Command {

	/** @var IUserManager */
	protected $userManager;

        /** @var PersonMapper Person mapper*/
	private $personMapper;

	/** @var IAppManager */
	private $appManager;

	/** @var PhotoAlbums */
	protected $photoAlbums;

	/** @var SettingsService */
	private $settingsService;

	/**
	 * @param IUserManager $userManager
	 * @param PersonMapper $personMapper
	 * @param PhotoAlbums $photoAlbums
	 * @param SettingsService $settingsService
	 * @param IAppManager $appManager
	 */
	public function __construct(IUserManager    $userManager,
	                            PersonMapper    $personMapper,
	                            IAppManager     $appManager,
	                            PhotoAlbums     $photoAlbums,
	                            SettingsService $settingsService)
	{
		parent::__construct();

		$this->appManager      = $appManager;
		$this->personMapper    = $personMapper;
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
			)->addOption(
				'list_person',
				'l',
				InputOption::VALUE_NONE,
				'List all persons defined for the given user_id.',
				null
			)->addOption(
				'person_name',
				'p',
				InputOption::VALUE_REQUIRED,
				'Sync albums for a given user and person name(s) (separate using comma). If not used, sync albums for all persons defined by the user.',
				null
			)->addOption(
				'mode',
				'm',
				InputOption::VALUE_REQUIRED,
				'Album creation mode. Use "album-per-person" to create one album for each given person via person_name parameter. Use "album-combined" to create one album for all person names given via person_name parameter.',
				'album-per-person'
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
		$person_name = $input->getOption('person_name');
		$mode = $input->getOption('mode');

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
			$this->userManager->callForAllUsers(function (IUser $iUser) use (&$users) {
				$users[] = $iUser->getUID();
			});
		}

		if ($input->getOption('list_person')) {
			if (is_null($userId)) {
				$output->writeln("List option requires option user_id!");
				return 1;
			} else{
				$output->writeln("List of defined persons for the user <$userId> :");
				$modelId = $this->settingsService->getCurrentFaceModel();
				$distintNames = $this->personMapper->findDistinctNames($userId, $modelId);
				foreach ($distintNames as $key=>$distintName) {
					if ($key > 0 ){
						$output->write(", ");
					}
					$output->write($distintName->getName());
				}
				$output->writeln("");
				$output->writeln("Done.");
			}
			return 0;
		}

		foreach ($users as $userId) {
			if (!is_null($person_name)) {
				if (is_null($userId)) {
					$output->writeln("Person_name option requires option user_id!");
					return 1;
				}
				$output->writeln("Synchronizing albums for the user <$userId> and person_name <$person_name> using mode <$mode>... ");
				if ($mode === "album-per-person") {
					$personList = explode(",", $person_name);
					foreach ($personList as $person) {
						$this->photoAlbums->syncUserPersonNamesSelected($userId, $person, $output);
					}
				}
				else if ($mode === "album-combined") {
					$personList = explode(",", $person_name); 
					if (count($personList) < 2) {
						$output->writeln("Note parameter mode <$mode> requires at least two persons separated using coma.");
						return 1;
					}
					$this->photoAlbums->syncUserPersonNamesCombinedAlbum($userId, $personList, $output);
				}
				else {
					$output->writeln("Error: invalid value for parameter mode <$mode>. ");
					return 1;
				}
				$output->writeln("Done.");
			} else {
				$output->write("Synchronizing albums for the user <$userId>... ");
				$this->photoAlbums->syncUser($userId);
				$output->writeln("Done.");
			}
		}

		return 0;
	}

}

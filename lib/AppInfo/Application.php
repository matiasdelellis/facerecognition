<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017-2018, 2020 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\FaceRecognition\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUserManager;

use OCA\Files\Event\LoadSidebar;

use OCA\FaceRecognition\Listener\LoadSidebarListener;
use OCA\FaceRecognition\Watcher;

class Application extends App {

	/** @var string */
	public const APP_NAME = 'facerecognition';

	/**
	 * Application constructor.
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_NAME, $urlParams);

		$this->connectWatcher();
		$this->connectSearch();
		$this->addServiceListenders();
	}

	private function connectWatcher() {
		/** @var IRootFolder $root */
		$root = $this->getContainer()->query(IRootFolder::class);

		$root->listen('\OC\Files', 'postWrite', function (Node $node) {
			/** @var Watcher $watcher */
			$watcher = \OC::$server->query(Watcher::class);
			$watcher->postWrite($node);
		});

		// We want to react on postDelete and not preDelete as in preDelete we don't know if
		// file actually got deleted (locked, other errors...)
		$root->listen('\OC\Files', 'postDelete', function (Node $node) {
			/** @var Watcher $watcher */
			$watcher = \OC::$server->query(Watcher::class);
			$watcher->postDelete($node);
		});

		// Watch for user deletion, so we clean up user data, after user gets deleted
		$userManager = $this->getContainer()->query(IUserManager::class);
		$userManager->listen('\OC\User', 'postDelete', function (\OC\User\User $user) {
			/** @var Watcher $watcher */
			$watcher = \OC::$server->query(Watcher::class);
			$watcher->postUserDelete($user);
		});
	}

	private function connectSearch() {
		$this->getContainer()->getServer()->getSearch()->registerProvider(
			'OCA\FaceRecognition\Search\Provider',
			array('app'=>'facerecognition', 'apps' => array('files'))
		);
	}

	private function addServiceListenders() {
		/** @var IEventDispatcher $dispatcher */
		$dispatcher = \OC::$server->query(IEventDispatcher::class);
		$dispatcher->addServiceListener(LoadSidebar::class, LoadSidebarListener::class);
	}

}

<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2017-2021 Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2020 Xiangbin Li >dassio@icloud.com>
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
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\IAppContainer;

use OCP\EventDispatcher\IEventDispatcher;

use OCA\Files\Event\LoadSidebar;
use OCP\User\Events\UserDeletedEvent;

use OCA\FaceRecognition\Hooks\FileHooks;

use OCA\FaceRecognition\Listener\LoadSidebarListener;
use OCA\FaceRecognition\Listener\UserDeletedListener;

use OCA\FaceRecognition\Search\PersonSearchProvider;

class Application extends App implements IBootstrap {

	/** @var string */
	public const APP_NAME = 'facerecognition';

	/**
	 * Application constructor.
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_NAME, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(PersonSearchProvider::class);

		$context->registerEventListener(LoadSidebar::class, LoadSidebarListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->getAppContainer()->get(FileHooks::class)->register();
	}

}

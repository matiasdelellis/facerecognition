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
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

use OCP\SabrePluginEvent;

use OCP\EventDispatcher\IEventDispatcher;

use OCA\Files\Event\LoadSidebar;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\User\Events\UserDeletedEvent;

use OCA\FaceRecognition\Dav\DavPlugin;

use OCA\FaceRecognition\Listener\LoadSidebarListener;
use OCA\FaceRecognition\Listener\PostDeleteListener;
use OCA\FaceRecognition\Listener\PostWriteListener;
use OCA\FaceRecognition\Listener\UserDeletedListener;

use OCA\FaceRecognition\Search\PersonSearchProvider;

class Application extends App implements IBootstrap {

	/** @var string */
	public const APP_NAME = 'facerecognition';

	public const STATE_UNDEFINED = 0;
	public const STATE_DISABLED = 1;
	public const STATE_NOT_SUPPORTED = 2;
	public const STATE_HAS_PERSONS = 3;
	public const STATE_NO_PERSONS = 4;

	public const DAV_NS_FACE_RECOGNITION = 'http://github.com/matiasdelellis/facerecognition/ns';

	public const DAV_PROPERTY_FACES = '{' . self::DAV_NS_FACE_RECOGNITION . '}faces';

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

		$context->registerEventListener(NodeWrittenEvent::class, PostWriteListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, PostDeleteListener::class);
	}

	public function boot(IBootContext $context): void {
		$eventDispatcher = $context->getServerContainer()->get(IEventDispatcher::class);
		$eventDispatcher->addListener('OCA\DAV\Connector\Sabre::addPlugin', function (SabrePluginEvent $event) use ($context) {
			$eventServer = $event->getServer();
			if ($eventServer !== null) {
				// We have to register the DavPlugin here and not info.xml,
				// because info.xml plugins are loaded, after the
				// beforeMethod:* hook has already been emitted.
				$plugin = $context->getAppContainer()->get(DavPlugin::class);
				$eventServer->addPlugin($plugin);
			}
		});
	}

}

<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@@gmail.com>
 *
 * @autor Matias De lellis <mati86dl@@gmail.com>
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

namespace OCA\FaceRecognition\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

use OCA\Files\Event\LoadSidebar;

class LoadSidebarListener implements IEventListener {

	public function handle(Event $event): void {
		if (!($event instanceof LoadSidebar)) {
			return;
		}

		Util::addScript('files', 'detailtabview');
		Util::addScript('facerecognition', 'vendor/lozad');
		Util::addScript('facerecognition', 'fr-dialogs');
		Util::addScript('facerecognition', 'personssidebar');
		Util::addStyle('facerecognition', 'fr-dialogs');
	}

}

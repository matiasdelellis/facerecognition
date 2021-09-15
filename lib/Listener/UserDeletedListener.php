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
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

use Psr\Log\LoggerInterface;

use OCA\FaceRecognition\Service\FaceManagementService;


class UserDeletedListener implements IEventListener {

	/** @var LoggerInterface $logger */
	private $logger;

	/** @var FaceManagementService */
	private $service;

	public function __construct(LoggerInterface       $logger,
	                            FaceManagementService $service)
	{
		$this->logger  = $logger;
		$this->service = $service;
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();

		$this->service->resetAllForUser($userId);

		$this->logger->info("Removed all face recognition data for deleted user " . $userId);
	}

}

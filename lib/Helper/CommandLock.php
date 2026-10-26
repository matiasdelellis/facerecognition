<?php
/**
 * @copyright Copyright (c) 2017, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
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
namespace OCA\FaceRecognition\Helper;

/**
 * Tasks that do flock over file and acts as a global mutex,
 * so we don't run more than one background task in parallel.
 */
class CommandLock {

	private static function LockFile(): string {
		return sys_get_temp_dir() . '/' . 'nextcloud_face_recognition_lock.pid';
	}

	/**
	 * @return string Description of the running command, or empty when there
	 *                is no lock file or it cannot be read.
	 */
	public static function IsLockedBy(): string {
		$lockFile = self::LockFile();
		if (!is_file($lockFile)) {
			return '';
		}

		$fp = fopen($lockFile, 'r');
		if ($fp === false) {
			return '';
		}

		$lockDescription = stream_get_contents($fp);
		fclose($fp);

		return $lockDescription === false ? '' : $lockDescription;
	}

	public static function lock(string $lockDescription) {
		// The 'e' mode sets close-on-exec on the descriptor, so the workers
		// that the coordinator forks (and that re-execute this command) drop
		// their copy of the lock: if the coordinator dies, the orphaned
		// workers must not keep blocking the command.
		$fp = fopen(self::LockFile(), 'ce');
		if (!$fp || !flock($fp, LOCK_EX | LOCK_NB, $eWouldBlock) || $eWouldBlock) {
			return null;
		}
		// The file is not truncated on open, so the text of a previous lock may
		// still be there. It is replaced only after the lock is acquired.
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, $lockDescription);
		return $fp;
	}

	public static function unlock($lockFile): void {
		// The file is deliberately not removed: unlinking a locked file lets
		// another process create a new file and take a lock that is not the
		// same one. The next lock truncates and rewrites the description.
		flock($lockFile, LOCK_UN);
		fclose($lockFile);
	}

}

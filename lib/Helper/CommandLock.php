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
	 * @return string
	 */
	public static function IsLockedBy(): string {
		$fp = fopen(self::LockFile(), 'r');
		$lockDescription = fread($fp, filesize(self::LockFile()));
		//fclose($fp);
		return $lockDescription;
	}

	public static function lock(string $lockDescription) {
		$fp = fopen(self::LockFile(), 'c');
		if (!$fp || !flock($fp, LOCK_EX | LOCK_NB, $eWouldBlock) || $eWouldBlock) {
			return null;
		}
		fwrite($fp, $lockDescription);
		return $fp;
	}

	public static function unlock($lockFile): void {
		flock($lockFile, LOCK_UN);
		unlink(self::LockFile());
	}

}
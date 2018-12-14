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
 * Tries to get total amount of memory on the host, given to PHP.
 */
class MemoryLimits {

	/**
	 * Tries to get memory available to PHP. This is highly speculative business.
	 * It will first try reading value of "memory_limit" and if it is -1, it will
	 * try to get 1/2 memory of host system. In case of any error, it will return
	 * negative value. Note that negative here doesn't mean "unlimited"! This
	 * function doesn't care if PHP is being used in CLI or FPM mode.
	 *
	 * @return int Total memory available to PHP, in bytes, or negative if
	 * we don't know any better
	 */
	public static function getAvailableMemory(): int {
		// Try first to get from php.ini
		try {
			$value = MemoryLimits::returnBytes(ini_get('memory_limit'));
			if ($value > 0) {
				return $value;
			}
		} catch (\Exception $e) {
			return -1;
		}

		// php.ini says that memory_limit is -1, which means unlimited.
		//We need to get memory from system (if system is supported here).
		// Only linux is currently supported.
		if (php_uname("s") === "Linux") {
			$linuxMemory = MemoryLimits::getTotalMemoryLinux();
			if ($linuxMemory <= 0) {
				return -3;
			}
			return intval($linuxMemory / 2);
		} else {
			return -2;
		}
	}

	private static function getTotalMemoryLinux(): int {
		$fh = fopen('/proc/meminfo','r');
		if ($fh === false) {
			return 0;
		}

		$mem = 0;
		while ($line = fgets($fh)) {
			$pieces = array();
			if (preg_match('/^MemTotal:\s+(\d+)\skB$/', $line, $pieces)) {
				$mem = $pieces[1];
				break;
			}
		}
		fclose($fh);
		$memKb = intval($mem);
		return $memKb * 1024;
	}

	/**
	 * Converts shorthand memory notation value to bytes
	 * From http://php.net/manual/en/function.ini-get.php
	 *
	 * @param string $val Memory size shorthand notation string
	 *
	 * @return int Value in integers (bytes)
	 */
	public static function returnBytes(string $val): int {
		$val = trim($val);
		if ($val === "") {
			return 0;
		}

		$valInt = intval($val);
		if ($valInt === 0) {
			return 0;
		}

		$last = strtolower($val[strlen($val)-1]);
		switch($last) {
			case 'g':
				$valInt *= 1024;
				// Fallthrough on purpose
			case 'm':
				$valInt *= 1024;
				// Fallthrough on purpose
			case 'k':
				$valInt *= 1024;
		}

		return $valInt;
	}
}
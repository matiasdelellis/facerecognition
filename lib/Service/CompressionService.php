<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2019-2024 Matias De lellis <mati86dl@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Service;

class CompressionService {

	/**
	 * Decompress according to file extension
	 *
	 * @param string $inputFile
	 * @param string $outputFile
	 *
	 * @throws \Exception
	 *
	 * @return void
	 */
	public function decompress(string $inputFile, string $outputFile): void {
		if (!file_exists ($inputFile) || !is_readable ($inputFile))
			throw new \Exception('The file ' . $inputFile . ' not exists or is not readable');

		if (!file_exists($outputFile)) {
			$outputDir = dirname($outputFile);
			if (!is_dir($outputDir))
				throw new \Exception('The directory ' . $outputDir . ' to write ' . basename($outputFile) . ' does not exist');
			if (!is_writeable($outputDir))
				throw new \Exception('The directory ' . $outputDir . ' is not writable');
		} else if (!is_writable($outputFile)) {
			throw new \Exception('The file ' . $outputFile . ' is not writable');
		}

		$extension = pathinfo($inputFile, PATHINFO_EXTENSION);
		switch ($extension) {
		case 'bz2':
			$this->bunzip2($inputFile, $outputFile);
			break;
		default:
			throw new \Exception('Unsupported file format: ' . $extension);
			break;
		}
	}

	private function bunzip2(string $inputFile, string $outputFile): void {
		$in_file = bzopen ($inputFile, "r");
		if ($in_file === false)
			throw new \Exception('Could not open the file to read: ' . $inputFile);

		$out_file = fopen ($outputFile, "w");
		if ($out_file === false) {
			bzclose ($in_file);
			throw new \Exception('Could not open the file to write: ' . $outputFile);
		}

		while (true) {
			// A read error gives false and the end of the file an empty string.
			// Looping on the buffer being truthy cannot tell one from the
			// other, and takes a read error for a clean end of file.
			$buffer = bzread ($in_file, 4096);
			if ($buffer === false)
				throw new \Exception('Read problem: ' . bzerrstr($in_file));
			if (bzerrno($in_file) !== 0)
				throw new \Exception('Compression problem: '. bzerrstr($in_file));
			if ($buffer === '')
				break;

			fwrite ($out_file, $buffer);
		}

		bzclose ($in_file);
		fclose ($out_file);
	}

}

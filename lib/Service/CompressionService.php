<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2019-2023 Matias De lellis <mati86dl@gmail.com>
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
	 * Uncompressing the file with the bzip2-extension
	 *
	 * @param string $inputFile
	 * @param string $outputFile
	 *
	 * @throws \Exception
	 *
	 * @return void
	 */
	public function bunzip2(string $inputFile, string $outputFile): void {
		if (!file_exists ($inputFile) || !is_readable ($inputFile))
			throw new \Exception('The file ' . $inputFile . ' not exists or is not readable');

		if ((!file_exists($outputFile) && !is_writeable(dirname($outputFile))) ||
		    (file_exists($outputFile) && !is_writable($outputFile)))
			throw new \Exception('The file ' . $outputFile . ' exists or is not writable');

		$in_file = bzopen ($inputFile, "r");
		$out_file = fopen ($outputFile, "w");

		if ($out_file === false)
			throw new \Exception('Could not open the file to write: ' . $outputFile);

		while ($buffer = bzread ($in_file, 4096)) {
			if($buffer === false)
				throw new \Exception('Read problem: ' . bzerrstr($in_file));
			if(bzerrno($in_file) !== 0)
				throw new \Exception('Compression problem: '. bzerrstr($in_file));

			fwrite ($out_file, $buffer, 4096);
		}

		bzclose ($in_file);
		fclose ($out_file);
	}

}

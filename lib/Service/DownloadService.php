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

use OCP\ITempManager;

class DownloadService {

	/** @var ITempManager */
	private $tempManager;

	public function __construct(ITempManager $tempManager)
	{
		$this->tempManager = $tempManager;
	}

	/**
	 * Download a file in a temporary folder
	 *
	 * @param string $fileUrl url to download.
	 * @return string temp file downloaded.
	 *
	 * @throws \Exception
	 */
	public function downloadFile(string $fileUrl): string {
		$tempFolder = $this->tempManager->getTemporaryFolder('/facerecognition/');
		if ($tempFolder === false) {
			throw new \Exception('Could not create the temporary folder for the download');
		}

		$path = parse_url($fileUrl, PHP_URL_PATH);
		$name = is_string($path) ? basename($path) : basename($fileUrl);
		$tempFile = $tempFolder . $name;

		$fp = fopen($tempFile, 'w+');
		if ($fp === false) {
			throw new \Exception('Could not open the file to write: ' . $tempFile);
		}

		$ch = curl_init($fileUrl);
		if ($ch === false) {
			fclose($fp);
			@unlink($tempFile);
			throw new \Exception('Curl error: unable to initialize curl');
		}

		curl_setopt_array($ch, [
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			// The model files are the executable weights of the application,
			// so the certificate has to be verified.
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 600,
			CURLOPT_USERAGENT => 'Nextcloud-Face-Recognition-Service',
		]);

		$downloaded = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		fclose($fp);

		if ($downloaded === false) {
			@unlink($tempFile);
			throw new \Exception('Curl error: ' . $curlError);
		}

		if ($httpCode !== 200) {
			$statusCodes = [
				400 => 'Bad request',
				401 => 'Unauthorized',
				403 => 'Forbidden',
				404 => 'Not Found',
				500 => 'Internal Server Error',
				502 => 'Bad Gateway',
				503 => 'Service Unavailable',
				504 => 'Gateway Timeout',
			];

			$message = 'Download failed';
			if(isset($statusCodes[$httpCode])) {
				$message .= ' - ' . $statusCodes[$httpCode] . ' (HTTP ' . $httpCode . ')';
			} else {
				$message .= ' - HTTP status code: ' . $httpCode;
			}

			if(!empty($curlError)) {
				$message .= ' - curl error message: ' . $curlError;
			}
			$message .= ' - URL: ' . htmlentities($fileUrl);

			@unlink($tempFile);
			throw new \Exception($message);
		}

		return $tempFile;
	}

	/**
	 * Remove any temporary file from the service.
	 *
	 * @return void
	 */
	public function clean(): void {
		$this->tempManager->clean();
	}

}

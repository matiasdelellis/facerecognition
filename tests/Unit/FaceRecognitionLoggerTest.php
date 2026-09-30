<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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
namespace OCA\FaceRecognition\Tests\Unit;

use OCA\FaceRecognition\BackgroundJob\FaceRecognitionLogger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Test\TestCase;

/**
 * Where the lines of the background job go. It only runs as a command, from
 * the system cron, whose output is usually thrown away: a warning has to
 * reach the Nextcloud log as well.
 */
class FaceRecognitionLoggerTest extends TestCase {

	public function testAWarningOfACommandRunGoesToTheConsoleAndToTheNextcloudLog() {
		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->once())->method('writeln')->with("\t[manual faces] Region 2: could not be searched");
		$log = $this->createMock(LoggerInterface::class);
		$log->expects($this->once())->method('warning')
			->with('[manual faces] Region 2: could not be searched', ['app' => 'facerecognition']);

		(new FaceRecognitionLogger($output, $log))->logWarning("\t[manual faces] Region 2: could not be searched");
	}

	public function testAnInfoOfACommandRunOnlyGoesToTheConsole() {
		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->once())->method('writeln');
		$log = $this->createMock(LoggerInterface::class);
		$log->expects($this->never())->method($this->anything());

		(new FaceRecognitionLogger($output, $log))->logInfo('Faces found: 1');
	}

	public function testAWarningWithOnlyTheNextcloudLogIsLoggedOnce() {
		$log = $this->createMock(LoggerInterface::class);
		$log->expects($this->once())->method('warning')->with('something failed');

		(new FaceRecognitionLogger($log))->logWarning('something failed');
	}
}

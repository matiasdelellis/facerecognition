<?php
/**
 * @copyright Copyright (c) 2026, Matias De lellis <mati86dl@gmail.com>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\FaceRecognition\BackgroundJob;

/**
 * Configuration of a parallel worker: which share of the images it has to
 * analyze, and how. The work is statically partitioned: each worker only takes
 * the images whose id, modulo the worker count, equals its own index.
 *
 * The mode is the mode of the whole run, which the worker inherits from the
 * coordinator. Of that run a worker only executes the analysis, but the mode
 * still tells it which pass to run: the first, fast pass of `--fast-mode`,
 * which uses a smaller model, or the refinement of every other mode.
 */
class WorkerConfig implements \JsonSerializable {

	private const WORKER_INDEX_KEY = 'workerIndex';
	private const WORKER_COUNT_KEY = 'workerCount';
	private const WORKER_MODE_KEY = 'mode';

	/** @var int */
	private $workerIndex;

	/** @var int */
	private $workerCount;

	/** @var string */
	private $mode;

	public function __construct(int $workerIndex, int $workerCount, string $mode) {
		$this->workerIndex = $workerIndex;
		$this->workerCount = $workerCount;
		$this->mode = $mode;
	}

	/**
	 * @param array $data The JSON-decoded worker configuration
	 *
	 * @throws \InvalidArgumentException If the given JSON data is not valid
	 */
	public static function fromJson(array $data): WorkerConfig {
		$workerIndex = $data[self::WORKER_INDEX_KEY] ?? null;
		if (!is_int($workerIndex)) {
			throw new \InvalidArgumentException('Invalid worker data: Missing worker index');
		}

		$workerCount = $data[self::WORKER_COUNT_KEY] ?? null;
		if (!is_int($workerCount)) {
			throw new \InvalidArgumentException('Invalid worker data: Missing worker count');
		}

		$mode = $data[self::WORKER_MODE_KEY] ?? null;
		if (!is_string($mode)) {
			throw new \InvalidArgumentException('Invalid worker data: Missing mode');
		}

		return new self($workerIndex, $workerCount, $mode);
	}

	public function getWorkerIndex(): int {
		return $this->workerIndex;
	}

	public function getWorkerCount(): int {
		return $this->workerCount;
	}

	public function getMode(): string {
		return $this->mode;
	}

	public function jsonSerialize(): mixed {
		return [
			self::WORKER_INDEX_KEY => $this->workerIndex,
			self::WORKER_COUNT_KEY => $this->workerCount,
			self::WORKER_MODE_KEY => $this->mode,
		];
	}
}

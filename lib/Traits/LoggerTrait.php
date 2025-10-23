<?php

namespace OCA\FaceRecognition\Traits;

use Psr\Log\LoggerInterface as ILogger;
use OCA\FaceRecognition\AppInfo\Application;

trait LoggerTrait {

	/** @var ILogger */
	protected ILogger $logger;

	public function setLogger(ILogger $logger): void {
		$this->logger = $logger;
	}

	/**
	 * Build a consistent context array for all log entries.
	 *
	 * @param array $extra Additional context data
	 * @return array
	 */
	protected function logContext(array $extra = []): array {
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

		return array_merge([
			'app'    => Application::APP_NAME,
			'method' => $trace[2]['function'] ?? 'unknown',
			'class'  => $trace[2]['class'] ?? 'unknown',
			'file'   => $trace[1]['file'] ?? __FILE__,
			'line'   => $trace[1]['line'] ?? __LINE__,
		], $extra);
	}

	/** Convenience wrappers for logger levels **/

	protected function logDebug(string $message, array $context = []): void {
		$this->logger->debug($message, $this->logContext($context));
	}

	protected function logInfo(string $message, array $context = []): void {
		$this->logger->info($message, $this->logContext($context));
	}

	protected function logWarning(string $message, array $context = []): void {
		$this->logger->warning($message, $this->logContext($context));
	}

	protected function logError(string $message, array $context = []): void {
		$this->logger->error($message, $this->logContext($context));
	}
}

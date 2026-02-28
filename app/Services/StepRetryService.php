<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Log;

/**
 * Exponential backoff retry wrapper for rail adapters.
 *
 * Transient failures (bank timeouts, network blips, rate limits from providers)
 * should not cause a full rollback. We retry the step up to MAX_ATTEMPTS times
 * with exponential backoff before giving up and triggering rollback.
 *
 * Retryable errors:  connection timeout, HTTP 502/503/504, "try again" messages
 * Non-retryable:     insufficient funds, invalid account, fraud decline, 400 errors
 *
 * Backoff schedule (seconds): 2, 4, 8 (doubles each attempt)
 */
class StepRetryService
{
  private const MAX_ATTEMPTS   = 3;
  private const BASE_DELAY_MS  = 2000; // 2 seconds

  // Substrings that indicate a transient error worth retrying
  private const RETRYABLE_PATTERNS = [
    'timeout',
    'timed out',
    'connection refused',
    'temporarily unavailable',
    'service unavailable',
    '502',
    '503',
    '504',
    'try again',
    'gateway',
    'network',
    'curl error',
  ];

  // Substrings that indicate a permanent error — never retry
  private const PERMANENT_PATTERNS = [
    'insufficient',
    'invalid account',
    'invalid bank',
    'account not found',
    'fraud',
    'blocked',
    'blacklisted',
    'do not honour',
    'restricted',
    'invalid token',
    'invalid wallet',
    'kyc',
  ];

  /**
   * Execute a callable (rail adapter call) with retry logic.
   *
   * @param  callable  $operation  fn() => mixed
   * @param  string    $stepLabel  For logging
   * @return mixed     The result of the callable on success
   * @throws \Throwable On permanent failure or exhausted retries
   */
  public function execute(callable $operation, string $stepLabel = 'step'): mixed
  {
    $lastException = null;

    for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
      try {
        return $operation();
      } catch (\Throwable $e) {
        $lastException = $e;
        $message = strtolower($e->getMessage());

        // Permanent failure — do not retry, throw immediately
        if ($this->isPermanent($message)) {
          Log::warning("Atlas step permanent failure [{$stepLabel}] attempt {$attempt}: {$e->getMessage()}");
          throw $e;
        }

        // Transient or unknown — log and retry if attempts remain
        if ($attempt < self::MAX_ATTEMPTS) {
          $delaySec = (self::BASE_DELAY_MS * (2 ** ($attempt - 1))) / 1000;
          Log::info(
            "Atlas step transient failure [{$stepLabel}] attempt {$attempt}/{$attempt}. " .
              "Retrying in {$delaySec}s. Error: {$e->getMessage()}"
          );
          usleep((int)($delaySec * 1_000_000));
        } else {
          Log::error(
            "Atlas step exhausted retries [{$stepLabel}] after " . self::MAX_ATTEMPTS .
              " attempts. Final error: {$e->getMessage()}"
          );
        }
      }
    }

    // All attempts exhausted
    throw new \RuntimeException(
      "Step [{$stepLabel}] failed after " . self::MAX_ATTEMPTS . " attempts. " .
        "Last error: " . $lastException->getMessage(),
      0,
      $lastException
    );
  }

  private function isPermanent(string $lowerMessage): bool
  {
    foreach (self::PERMANENT_PATTERNS as $pattern) {
      if (str_contains($lowerMessage, $pattern)) {
        return true;
      }
    }
    return false;
  }

  private function isRetryable(string $lowerMessage): bool
  {
    foreach (self::RETRYABLE_PATTERNS as $pattern) {
      if (str_contains($lowerMessage, $pattern)) {
        return true;
      }
    }
    return false;
  }
}

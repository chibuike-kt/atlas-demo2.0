<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency guard for rule executions.
 *
 * Problem: Client sends "execute rule", network times out, client retries.
 * Without this, two executions run and the user is double-debited.
 *
 * Solution: Before any execution, acquire a cache lock keyed to
 * (user_id + rule_id + idempotency_key). If the lock exists, return
 * the original execution result instead of running again.
 *
 * The idempotency key is generated client-side (UUID) and sent in the
 * X-Idempotency-Key header. If not provided, we generate one from
 * user + rule + current minute — coarse but safe for scheduler triggers.
 */
class IdempotencyService
{
  private const TTL_SECONDS   = 86400; // 24 hours
  private const LOCK_SECONDS  = 30;    // max time for one execution

  /**
   * Try to acquire an execution lock.
   * Returns the lock object on success, or throws if already running.
   */
  public function acquireLock(string $userId, string $ruleId, string $idempotencyKey): \Illuminate\Cache\CacheLock|\Illuminate\Contracts\Cache\Lock
  {
    $lockKey = $this->lockKey($userId, $ruleId, $idempotencyKey);

    $lock = Cache::lock($lockKey, self::LOCK_SECONDS);

    if (!$lock->get()) {
      throw new \RuntimeException(
        'This rule is already executing. Please wait for the current execution to complete.'
      );
    }

    return $lock;
  }

  /**
   * Check if this idempotency key already has a completed execution.
   * If yes, return the cached execution ID so the controller can
   * return the existing result without running again.
   */
  public function getExistingResult(string $userId, string $ruleId, string $idempotencyKey): ?string
  {
    return Cache::get($this->resultKey($userId, $ruleId, $idempotencyKey));
  }

  /**
   * Record that an execution completed for this idempotency key.
   * Stored for 24h so retries within a day always get the same result.
   */
  public function recordResult(string $userId, string $ruleId, string $idempotencyKey, string $executionId): void
  {
    Cache::put(
      $this->resultKey($userId, $ruleId, $idempotencyKey),
      $executionId,
      self::TTL_SECONDS
    );
  }

  /**
   * Generate a deterministic idempotency key for scheduler-triggered
   * executions (no client-provided key). Keyed to minute so the
   * scheduler can't fire the same rule twice in the same minute.
   */
  public function schedulerKey(string $userId, string $ruleId): string
  {
    return hash('sha256', "scheduler:{$userId}:{$ruleId}:" . date('YmdHi'));
  }

  private function lockKey(string $userId, string $ruleId, string $key): string
  {
    return "atlas:exec_lock:{$userId}:{$ruleId}:" . hash('sha256', $key);
  }

  private function resultKey(string $userId, string $ruleId, string $key): string
  {
    return "atlas:exec_result:{$userId}:{$ruleId}:" . hash('sha256', $key);
  }
}

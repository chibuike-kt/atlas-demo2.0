<?php

namespace App\Services\Security;

use App\Models\RuleExecution;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Velocity fraud checks.
 *
 * Checks run BEFORE an execution is allowed to proceed.
 * Any failure throws a RuntimeException which the ExecutionController
 * catches and returns as a 429 / 403.
 *
 * Limits (conservative — tighten in production):
 *   Per rule:  max 10 executions per hour, 50 per day
 *   Per user:  max 20 executions per hour, 100 per day
 *   Amount:    single execution > ₦5,000,000 requires extra check
 *   Anomaly:   execution > 3× user's 30-day average triggers a hold
 */
class VelocityService
{
  // Per-rule limits
  private const RULE_MAX_PER_HOUR = 10;
  private const RULE_MAX_PER_DAY  = 50;

  // Per-user limits
  private const USER_MAX_PER_HOUR = 20;
  private const USER_MAX_PER_DAY  = 100;

  // Single-execution amount ceiling before anomaly check kicks in
  private const LARGE_AMOUNT_NGN = 5_000_000;

  // Multiplier over 30-day average that triggers anomaly hold
  private const ANOMALY_MULTIPLIER = 3.0;

  /**
   * Run all velocity checks. Throws on any violation.
   */
  public function check(User $user, string $ruleId, float $amountNgn): void
  {
    $this->checkRuleVelocity($ruleId);
    $this->checkUserVelocity($user->id);
    $this->checkAmountAnomaly($user->id, $amountNgn);
  }

  private function checkRuleVelocity(string $ruleId): void
  {
    $hourKey = "atlas:vel:rule_hour:{$ruleId}:" . date('YmdH');
    $dayKey  = "atlas:vel:rule_day:{$ruleId}:"  . date('Ymd');

    $hourCount = (int) Cache::get($hourKey, 0);
    $dayCount  = (int) Cache::get($dayKey,  0);

    if ($hourCount >= self::RULE_MAX_PER_HOUR) {
      throw new \RuntimeException(
        "Rule execution limit reached ({$hourCount} in the last hour). Please wait before running again.",
        429
      );
    }

    if ($dayCount >= self::RULE_MAX_PER_DAY) {
      throw new \RuntimeException(
        "Daily rule execution limit reached ({$dayCount} today). Resets at midnight.",
        429
      );
    }

    // Increment counters (set expiry on first write)
    Cache::put($hourKey, $hourCount + 1, 3600);
    Cache::put($dayKey,  $dayCount  + 1, 86400);
  }

  private function checkUserVelocity(string $userId): void
  {
    $hourKey = "atlas:vel:user_hour:{$userId}:" . date('YmdH');
    $dayKey  = "atlas:vel:user_day:{$userId}:"  . date('Ymd');

    $hourCount = (int) Cache::get($hourKey, 0);
    $dayCount  = (int) Cache::get($dayKey,  0);

    if ($hourCount >= self::USER_MAX_PER_HOUR) {
      throw new \RuntimeException(
        "Too many executions in the last hour. Please slow down.",
        429
      );
    }

    if ($dayCount >= self::USER_MAX_PER_DAY) {
      throw new \RuntimeException(
        "Daily execution limit reached. Resets at midnight.",
        429
      );
    }

    Cache::put($hourKey, $hourCount + 1, 3600);
    Cache::put($dayKey,  $dayCount  + 1, 86400);
  }

  /**
   * Flag executions that are unusually large vs the user's history.
   *
   * We use a cached 30-day average so we don't hit the DB on every execution.
   * Cache is invalidated after each execution so the average stays fresh.
   *
   * If the amount is > ANOMALY_MULTIPLIER × average, we throw — the
   * ExecutionController logs this as a fraud flag and holds the execution
   * for manual review (or you can auto-decline in production).
   */
  private function checkAmountAnomaly(string $userId, float $amountNgn): void
  {
    // Small amounts never need anomaly check
    if ($amountNgn < self::LARGE_AMOUNT_NGN) {
      return;
    }

    $cacheKey = "atlas:vel:avg:{$userId}";
    $avg30Day = Cache::remember($cacheKey, 3600, function () use ($userId) {
      return (float) RuleExecution::where('user_id', $userId)
        ->where('status', 'completed')
        ->where('started_at', '>=', now()->subDays(30))
        ->avg('total_amount_ngn') ?? 0;
    });

    // New user with no history — skip anomaly check
    if ($avg30Day === 0.0) {
      return;
    }

    if ($amountNgn > ($avg30Day * self::ANOMALY_MULTIPLIER)) {
      $avgFormatted = '₦' . number_format($avg30Day, 2);
      $amtFormatted = '₦' . number_format($amountNgn, 2);
      throw new \RuntimeException(
        "This execution amount ({$amtFormatted}) is unusually large compared to your recent average ({$avgFormatted}). " .
          "For your security, this has been flagged for review. Contact support if this is expected.",
        403
      );
    }
  }

  /**
   * Invalidate the cached average after a successful execution.
   * Call this from ExecutionEngine after completion.
   */
  public function invalidateAverageCache(string $userId): void
  {
    Cache::forget("atlas:vel:avg:{$userId}");
  }
}

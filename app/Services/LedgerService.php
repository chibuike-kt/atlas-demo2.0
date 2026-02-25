<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\RuleExecution;
use App\Models\ExecutionStep;
use App\Models\User;

class LedgerService
{
  /**
   * Record the initial debit when a rule fires.
   * The full amount is debited from the connected account.
   */
  public function recordDebit(
    RuleExecution $execution,
    User $user,
    string $amountNgn,
    string $description
  ): LedgerEntry {
    $lastBalance = $this->lastBalance($user->id, 'NGN');

    return LedgerEntry::create([
      'execution_id'  => $execution->id,
      'user_id'       => $user->id,
      'entry_type'    => 'debit',
      'amount'        => $amountNgn,
      'currency'      => 'NGN',
      'description'   => $description,
      'reference'     => $execution->id,
      'balance_before' => $lastBalance,
      'balance_after' => bcsub($lastBalance, $amountNgn, 6),
    ]);
  }

  /**
   * Record a credit for each completed action step.
   */
  public function recordStepCredit(
    RuleExecution $execution,
    ExecutionStep $step,
    User $user,
    string $amountNgn,
    string $currency,
    string $description,
    string $reference
  ): LedgerEntry {
    $lastBalance = $this->lastBalance($user->id, $currency);

    return LedgerEntry::create([
      'execution_id'      => $execution->id,
      'execution_step_id' => $step->id,
      'user_id'           => $user->id,
      'entry_type'        => 'credit',
      'amount'            => $amountNgn,
      'currency'          => $currency,
      'description'       => $description,
      'reference'         => $reference,
      'balance_before'    => $lastBalance,
      'balance_after'     => bcadd($lastBalance, $amountNgn, 6),
    ]);
  }

  public function history(string $userId, string $currency = 'NGN'): array
  {
    return LedgerEntry::where('user_id', $userId)
      ->where('currency', $currency)
      ->orderBy('created_at', 'desc')
      ->limit(50)
      ->get()
      ->map(fn($e) => [
        'id'          => $e->id,
        'type'        => $e->entry_type,
        'amount'      => $e->formattedAmount(),
        'currency'    => $e->currency,
        'description' => $e->description,
        'reference'   => $e->reference,
        'balance'     => '₦' . number_format((float)$e->balance_after, 2),
        'date'        => $e->created_at->toISOString(),
      ])
      ->toArray();
  }

  private function lastBalance(string $userId, string $currency): string
  {
    $last = LedgerEntry::where('user_id', $userId)
      ->where('currency', $currency)
      ->orderBy('created_at', 'desc')
      ->first();

    return $last ? (string) $last->balance_after : '0.000000';
  }
}

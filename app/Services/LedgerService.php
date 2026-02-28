<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\RuleExecution;
use App\Models\ExecutionStep;
use App\Models\User;

class LedgerService
{
  /**
   * Record the single debit when a rule fires.
   *
   * Amount = movement + service charge combined.
   * The service_charge is stored for internal audit but never surfaced to the user
   * directly — exactly like how GTBank records "transfer + fee" as one debit line.
   */
  public function recordDebit(
    RuleExecution $execution,
    User          $user,
    string        $amount,        // total_debit = movement + service charge
    string        $description,
    string        $serviceCharge = '0'
  ): LedgerEntry {
    $lastBalance = $this->lastBalance($user->id, 'NGN');

    return LedgerEntry::create([
      'execution_id'    => $execution->id,
      'user_id'         => $user->id,
      'entry_type'      => 'debit',
      'amount'          => $amount,
      'currency'        => 'NGN',
      'description'     => $description,
      'reference'       => $execution->id,
      'service_charge'  => $serviceCharge,   // stored for admin view
      'balance_before'  => $lastBalance,
      'balance_after'   => bcsub($lastBalance, $amount, 6),
    ]);
  }

  /**
   * Record a credit for a completed NGN step (bank transfer, PiggyVest, etc.)
   */
  public function recordStepCredit(
    RuleExecution $execution,
    ExecutionStep $step,
    User          $user,
    string        $amount,
    string        $currency,
    string        $description,
    string        $reference
  ): LedgerEntry {
    $lastBalance = $this->lastBalance($user->id, $currency);

    return LedgerEntry::create([
      'execution_id'      => $execution->id,
      'execution_step_id' => $step->id,
      'user_id'           => $user->id,
      'entry_type'        => 'credit',
      'amount'            => $amount,
      'currency'          => $currency,
      'description'       => $description,
      'reference'         => $reference,
      'service_charge'    => '0',
      'balance_before'    => $lastBalance,
      'balance_after'     => bcadd($lastBalance, $amount, 6),
    ]);
  }

  /**
   * Record a crypto conversion step.
   *
   * Stores BOTH the NGN spent and the token amount received as separate fields.
   * The ledger entry amount is in the token's denomination (USDT, not NGN).
   * This is how crypto exchanges record it: you spent X NGN, you received Y USDT.
   * The FX margin is Atlas's business — it lives in fee_ledger only.
   *
   * Display example (Spenda-style):
   *   Spent:    ₦150,200.00 NGN
   *   Received: 89.47 USDT
   *   Rate:     ₦1,679/USDT  (Atlas rate, margin already included)
   *   (user never sees market rate or spread)
   */
  public function recordCryptoCredit(
    RuleExecution $execution,
    ExecutionStep $step,
    User          $user,
    string        $amountNgn,     // NGN that was spent (for audit trail)
    string        $amountToken,   // USDT/token received (for user-facing display)
    string        $token,         // 'USDT', 'USDC', etc.
    string        $network,       // 'trc20', 'bep20', etc.
    string        $description,
    string        $reference
  ): LedgerEntry {
    // Track token balance separately from NGN
    $lastTokenBalance = $this->lastBalance($user->id, $token);

    return LedgerEntry::create([
      'execution_id'      => $execution->id,
      'execution_step_id' => $step->id,
      'user_id'           => $user->id,
      'entry_type'        => 'credit',
      'amount'            => $amountToken,       // stored as token units
      'currency'          => $token,             // 'USDT' not 'NGN'
      'amount_ngn'        => $amountNgn,         // NGN spent (audit only)
      'crypto_network'    => $network,
      'description'       => $description,
      'reference'         => $reference,
      'service_charge'    => '0',
      'balance_before'    => $lastTokenBalance,
      'balance_after'     => bcadd($lastTokenBalance, $amountToken, 8),
    ]);
  }

  /**
   * User-facing ledger history.
   * Returns both NGN and crypto entries merged, formatted correctly.
   * Crypto amounts show as "89.47 USDT" — never as naira.
   * Service charges are NOT shown to the user.
   */
  public function history(string $userId): array
  {
    return LedgerEntry::where('user_id', $userId)
      ->orderBy('created_at', 'desc')
      ->limit(100)
      ->get()
      ->map(fn($e) => [
        'id'          => $e->id,
        'type'        => $e->entry_type,
        'amount'      => $this->formatAmount($e),
        'currency'    => $e->currency,
        'description' => $e->description,
        'reference'   => $e->reference,
        'balance'     => $this->formatBalance($e),
        'date'        => $e->created_at->toISOString(),
      ])
      ->toArray();
  }

  /**
   * Admin-facing history — includes service charges and FX margin data.
   * Never returned to the user-facing API.
   */
  public function adminHistory(string $userId): array
  {
    return LedgerEntry::where('user_id', $userId)
      ->orderBy('created_at', 'desc')
      ->limit(200)
      ->get()
      ->map(fn($e) => [
        'id'             => $e->id,
        'type'           => $e->entry_type,
        'amount'         => $this->formatAmount($e),
        'amount_raw'     => (float) $e->amount,
        'currency'       => $e->currency,
        'amount_ngn'     => $e->amount_ngn ? '₦' . number_format((float) $e->amount_ngn, 2) : null,
        'service_charge' => (float) ($e->service_charge ?? 0) > 0
          ? '₦' . number_format((float) $e->service_charge, 2)
          : null,
        'description'    => $e->description,
        'reference'      => $e->reference,
        'balance_before' => $this->formatBalanceRaw($e, 'before'),
        'balance_after'  => $this->formatBalance($e),
        'date'           => $e->created_at->toISOString(),
      ])
      ->toArray();
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Private helpers
  // ─────────────────────────────────────────────────────────────────────────

  private function lastBalance(string $userId, string $currency): string
  {
    $last = LedgerEntry::where('user_id', $userId)
      ->where('currency', $currency)
      ->orderBy('created_at', 'desc')
      ->first();

    return $last ? (string) $last->balance_after : '0.000000';
  }

  private function formatAmount(LedgerEntry $e): string
  {
    $amount = (float) $e->amount;

    // Crypto currencies — show in token denomination, never naira
    if (!in_array($e->currency, ['NGN', 'USD'])) {
      $sign = $e->entry_type === 'debit' ? '-' : '+';
      return $sign . number_format($amount, 4) . ' ' . $e->currency;
    }

    // NGN
    $symbol = $e->currency === 'USD' ? '$' : '₦';
    $sign   = $e->entry_type === 'debit' ? '-' : '';
    return $sign . $symbol . number_format($amount, 2);
  }

  private function formatBalance(LedgerEntry $e): string
  {
    $amount = (float) $e->balance_after;
    if (!in_array($e->currency, ['NGN', 'USD'])) {
      return number_format($amount, 4) . ' ' . $e->currency;
    }
    $symbol = $e->currency === 'USD' ? '$' : '₦';
    return $symbol . number_format($amount, 2);
  }

  private function formatBalanceRaw(LedgerEntry $e, string $which): string
  {
    $amount = (float) ($which === 'before' ? $e->balance_before : $e->balance_after);
    if (!in_array($e->currency, ['NGN', 'USD'])) {
      return number_format($amount, 4) . ' ' . $e->currency;
    }
    return '₦' . number_format($amount, 2);
  }
}

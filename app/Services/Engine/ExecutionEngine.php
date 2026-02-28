<?php

namespace App\Services\Engine;

use App\Models\AtlasWallet;
use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use App\Models\FeeLedger;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Services\EncryptionService;
use App\Services\FeeService;
use App\Services\LedgerService;
use App\Services\ReceiptService;
use App\Services\Rails\BillRail;
use App\Services\Rails\CowrywiseRail;
use App\Services\Rails\CryptoRail;
use App\Services\Rails\FiatRail;
use App\Services\Rails\PiggyVestRail;
use Illuminate\Support\Facades\Log;

class ExecutionEngine
{
  public function __construct(
    private readonly LedgerService     $ledger,
    private readonly RollbackManager   $rollback,
    private readonly EncryptionService $encryption,
  ) {}

  public function execute(Rule $rule, string $triggeredBy = 'manual'): RuleExecution
  {
    $user       = $rule->user;
    $account    = $rule->connectedAccount;
    $feeService = new FeeService();

    // ── 1. Load ordered actions ───────────────────────────────────────────
    $actions        = $rule->actions()->orderBy('step_order')->get();
    $totalStepCount = $actions->count();

    // ── 2. Resolve the movement amount (what the rule distributes) ────────
    $movementAmount = $this->resolveMovementAmount($rule, $account);

    // ── 3. Calculate service charge ───────────────────────────────────────
    // Like a bank transfer fee: user pays movement + charge together.
    // The charge is NEVER shown to the user as a line item — it is simply
    // baked into the total debit, exactly like GTBank, Access, Paystack do.
    $execFeeData   = $feeService->executionFee($totalStepCount);
    $execFeeNgn    = (string) $execFeeData['fee_amount_ngn']; // e.g. "20.000000"

    // ── 4. Total debit = movement + service charge ────────────────────────
    // This is the single number that leaves the user's account.
    // Receipt will show: "Moved ₦X,XXX.XX · Service charge ₦20 · Total ₦X,XXX.XX+20"
    // but NOT expose what the service charge is for internally.
    $totalDebit = bcadd($movementAmount, $execFeeNgn, 6);

    // ── 5. Balance check against total debit ─────────────────────────────
    $currentBalance = (string) $account->balance;
    if (bccomp($currentBalance, $totalDebit, 2) < 0) {
      $needed    = number_format((float) $totalDebit, 2);
      $available = number_format((float) $currentBalance, 2);
      $charge    = number_format((float) $execFeeNgn, 2);
      $movement  = number_format((float) $movementAmount, 2);
      throw new \RuntimeException(
        "Insufficient balance. Available: ₦{$available}, " .
          "Required: ₦{$needed} (₦{$movement} + ₦{$charge} service charge)"
      );
    }

    // ── 6. Create execution record ────────────────────────────────────────
    $execution = RuleExecution::create([
      'rule_id'             => $rule->id,
      'user_id'             => $user->id,
      'triggered_by'        => $triggeredBy,
      'rule_snapshot'       => $rule->snapshot(),
      'total_amount_ngn'    => $movementAmount,  // movement only — used for step distribution
      'service_charge_ngn'  => $execFeeNgn,      // internal — not shown to user
      'total_debit_ngn'     => $totalDebit,       // actual amount leaving account
      'status'              => 'running',
      'started_at'          => now(),
    ]);

    // ── 7. Single debit: movement + charge leaves the account together ────
    // We do ONE debit for the full amount so the ledger is clean.
    $this->ledger->recordDebit(
      execution: $execution,
      user: $user,
      amount: $totalDebit,
      description: "Atlas rule \"{$rule->name}\"",
      serviceCharge: $execFeeNgn
    );
    // Actually decrement the account balance — this is what was missing before
    $account->decrement('balance', (float) $totalDebit);

    // ── 8. Record Atlas revenue (internal only, never user-visible) ───────
    if ((float) $execFeeNgn > 0) {
      FeeLedger::create([
        'user_id'            => $user->id,
        'execution_id'       => $execution->id,
        'execution_step_id'  => null,
        'fee_type'           => 'service_charge',
        'transaction_amount' => $movementAmount,
        'fee_amount'         => $execFeeNgn,
        'fee_rate'           => $execFeeNgn,
        'currency'           => 'NGN',
        'description'        => $execFeeData['description'],
        'meta'               => $execFeeData,
      ]);
    }

    // ── 9. Execute each step ──────────────────────────────────────────────
    foreach ($actions as $action) {
      $stepAmountNgn = $action->resolveAmount($movementAmount);
      $config        = is_array($action->config)
        ? $action->config
        : (json_decode($action->config, true) ?? []);
      $config['user_id'] = $user->id;

      $step = ExecutionStep::create([
        'execution_id'   => $execution->id,
        'rule_action_id' => $action->id,
        'step_order'     => $action->step_order,
        'action_type'    => $action->action_type,
        'label'          => $action->label,
        'amount_ngn'     => $stepAmountNgn,
        'status'         => 'running',
        'started_at'     => now(),
      ]);

      try {
        $adapter = $this->resolveAdapter($action->action_type);
        $result  = $adapter->execute($config, (float) $stepAmountNgn);

        $step->update([
          'status'           => 'completed',
          'rail_reference'   => $result['rail_reference'],
          'result'           => $result['result'],
          'rollback_payload' => $result['rollback_payload'] ?? null,
          'completed_at'     => now(),
        ]);

        // ── Credit ledger — crypto steps use token denomination ────────
        // For NGN steps: credit in NGN
        // For crypto steps: credit in USDT (or token) — NOT in NGN equivalent
        // This is what the user actually received — the USDT amount.
        $isCrypto = $action->action_type === 'convert_crypto';
        if ($isCrypto) {
          $tokenAmt  = (string) ($result['result']['amount_token'] ?? '0');
          $token     = $result['result']['token'] ?? 'USDT';
          $network   = $result['result']['network'] ?? 'trc20';

          $this->ledger->recordCryptoCredit(
            execution: $execution,
            step: $step,
            user: $user,
            amountNgn: $stepAmountNgn,   // NGN spent (for audit)
            amountToken: $tokenAmt,        // USDT received (for display)
            token: $token,
            network: $network,
            description: $action->label ?? "Converted to {$token}",
            reference: $result['rail_reference']
          );

          // FX margin is baked into the rate — record for Atlas revenue tracking
          $this->recordCryptoRevenue($feeService, $execution, $step, $user, $stepAmountNgn, $config, $result);

          // Update Atlas in-app wallet balance
          $this->creditAtlasWallet($user->id, $network, $token, $tokenAmt);
        } else {
          $this->ledger->recordStepCredit(
            execution: $execution,
            step: $step,
            user: $user,
            amount: $stepAmountNgn,
            currency: 'NGN',
            description: $action->label ?? $action->action_type,
            reference: $result['rail_reference']
          );
        }
      } catch (\Throwable $e) {
        $step->update([
          'status'        => 'failed',
          'error_message' => $e->getMessage(),
          'completed_at'  => now(),
        ]);

        $execution->update([
          'status'        => 'failed',
          'error_message' => "Step {$action->step_order} failed: " . $e->getMessage(),
          'completed_at'  => now(),
        ]);

        // Restore full debit (movement + service charge) on failure
        $account->increment('balance', (float) $totalDebit);

        $this->rollback->rollback($execution->fresh());

        activity()
          ->causedBy($user)
          ->performedOn($execution)
          ->withProperties(['step' => $action->step_order, 'error' => $e->getMessage()])
          ->log('execution.failed');

        throw new \RuntimeException(
          "Execution failed at step {$action->step_order} ({$action->label}): " . $e->getMessage()
        );
      }
    }

    // ── 10. Mark complete ─────────────────────────────────────────────────
    $execution->update([
      'status'       => 'completed',
      'completed_at' => now(),
    ]);

    $rule->increment('execution_count');
    $rule->update(['last_triggered_at' => now()]);

    // ── 11. Generate receipt ──────────────────────────────────────────────
    try {
      (new ReceiptService())->generate(
        $execution->fresh(['steps', 'user', 'rule'])
      );
    } catch (\Throwable $e) {
      Log::warning("Receipt generation failed for execution {$execution->id}: " . $e->getMessage());
    }

    activity()
      ->causedBy($user)
      ->performedOn($execution)
      ->withProperties([
        'movement_ngn'     => $movementAmount,
        'service_charge'   => $execFeeNgn,
        'total_debit'      => $totalDebit,
        'steps'            => $actions->count(),
      ])
      ->log('execution.completed');

    return $execution->fresh(['steps']);
  }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

  /**
   * Record FX spread revenue for crypto conversions.
   * This is Atlas's margin baked into the buy rate — never shown to the user.
   * Spenda-style: user deposits $150, gets value for $148.65 — the $1.35 is margin.
   */
  private function recordCryptoRevenue(
    FeeService     $feeService,
    RuleExecution  $execution,
    ExecutionStep  $step,
    mixed          $user,
    string         $stepAmountNgn,
    array          $config,
    array          $result
  ): void {
    try {
      $cryptoFee = $feeService->cryptoConversionFee(
        (float) $stepAmountNgn,
        $config['token'] ?? 'USDT'
      );
      if ($cryptoFee['fee_amount_ngn'] > 0) {
        FeeLedger::create([
          'user_id'            => $user->id,
          'execution_id'       => $execution->id,
          'execution_step_id'  => $step->id,
          'fee_type'           => 'fx_margin',        // renamed from crypto_conversion for clarity
          'transaction_amount' => $stepAmountNgn,
          'fee_amount'         => $cryptoFee['fee_amount_ngn'],
          'fee_rate'           => $cryptoFee['fee_rate'],
          'currency'           => 'NGN',
          'description'        => $cryptoFee['description'],
          'meta'               => array_merge($cryptoFee, [
            'atlas_rate'    => $result['result']['atlas_rate']  ?? null,
            'market_rate'   => $result['result']['market_rate'] ?? null,
            'amount_token'  => $result['result']['amount_token'] ?? null,
            'token'         => $result['result']['token']        ?? null,
          ]),
        ]);
      }
    } catch (\Throwable $e) {
      Log::warning("FX margin record failed for step {$step->id}: " . $e->getMessage());
    }
  }

  /**
   * Credit the user's Atlas in-app wallet after a successful conversion.
   */
  private function creditAtlasWallet(
    string $userId,
    string $network,
    string $token,
    string $amountToken
  ): void {
    try {
      $wallet = AtlasWallet::firstOrNew([
        'user_id' => $userId,
        'network' => $network,
        'token'   => $token,
      ]);

      if (!$wallet->address) {
        $wallet->address = AtlasWallet::generateAddress($network);
      }

      $wallet->balance         = bcadd((string)($wallet->balance         ?? '0'), $amountToken, 8);
      $wallet->total_deposited = bcadd((string)($wallet->total_deposited ?? '0'), $amountToken, 8);
      $wallet->last_activity_at = now();
      $wallet->save();
    } catch (\Throwable $e) {
      Log::warning("Atlas wallet credit failed: " . $e->getMessage());
    }
  }

  /**
   * Resolve how much NGN the rule is moving (excluding service charge).
   */
  private function resolveMovementAmount(Rule $rule, ConnectedAccount $account): string
  {
    return match ($rule->total_amount_type) {
      'fixed'        => (string) $rule->total_amount,
      'full_balance' => (string) $account->balance,
      'percentage'   => bcdiv(
        bcmul((string) $account->balance, (string) $rule->total_amount, 10),
        '100',
        6
      ),
      default        => throw new \RuntimeException(
        'Unknown amount type: ' . $rule->total_amount_type
      ),
    };
  }

  private function resolveAdapter(string $actionType): mixed
  {
    return match ($actionType) {
      'send_bank'      => new FiatRail($this->encryption),
      'save_piggyvest' => new PiggyVestRail(),
      'save_cowrywise' => new CowrywiseRail(),
      'convert_crypto' => new CryptoRail(),
      'pay_bill'       => new BillRail(),
      default          => throw new \RuntimeException("No adapter for action type: {$actionType}"),
    };
  }
}

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

    // Resolve total NGN amount
    $totalAmount = $this->resolveAmount($rule, $account);

    // Validate balance
    if ((float) $account->balance < (float) $totalAmount) {
      throw new \RuntimeException(
        "Insufficient balance. Available: ₦" . number_format($account->balance, 2) .
          ", Required: ₦" . number_format((float) $totalAmount, 2)
      );
    }

    // Load actions
    $actions = $rule->actions()->orderBy('step_order')->get();

    // Pre-calculate fees for entire execution
    $actionsArray    = $actions->map(fn($a) => [
      'action_type' => $a->action_type,
      'amount'      => $a->resolveAmount($totalAmount),
      'config'      => is_array($a->config) ? $a->config : json_decode($a->config, true) ?? [],
    ])->toArray();
    $feeBreakdown    = $feeService->calculateForExecution($actionsArray);
    $sendBankCount   = collect($actionsArray)->where('action_type', 'send_bank')->count();
    $bankFeePerStep  = $sendBankCount >= FeeService::FIAT_BULK_THRESHOLD
      ? FeeService::FIAT_BULK_FEE
      : FeeService::FIAT_STEP_FEE;

    // Create execution record
    $execution = RuleExecution::create([
      'rule_id'          => $rule->id,
      'user_id'          => $user->id,
      'triggered_by'     => $triggeredBy,
      'rule_snapshot'    => $rule->snapshot(),
      'total_amount_ngn' => $totalAmount,
      'status'           => 'running',
      'started_at'       => now(),
    ]);

    // Debit account
    $this->ledger->recordDebit(
      $execution,
      $user,
      $totalAmount,
      "Rule: {$rule->name} — debited from {$account->institution_name}"
    );
    $account->decrement('balance', (float) $totalAmount);

    // Execute each step
    foreach ($actions as $action) {
      $stepAmount = $action->resolveAmount($totalAmount);
      $config     = is_array($action->config)
        ? $action->config
        : (json_decode($action->config, true) ?? []);
      $config['user_id'] = $user->id;

      $step = ExecutionStep::create([
        'execution_id'   => $execution->id,
        'rule_action_id' => $action->id,
        'step_order'     => $action->step_order,
        'action_type'    => $action->action_type,
        'label'          => $action->label,
        'amount_ngn'     => $stepAmount,
        'status'         => 'running',
        'started_at'     => now(),
      ]);

      try {
        $adapter = $this->resolveAdapter($action->action_type);
        $result  = $adapter->execute($config, (float) $stepAmount);

        $step->update([
          'status'           => 'completed',
          'rail_reference'   => $result['rail_reference'],
          'result'           => $result['result'],
          'rollback_payload' => $result['rollback_payload'],
          'completed_at'     => now(),
        ]);

        // Ledger credit
        $currency = $action->action_type === 'convert_crypto' ? 'USDT' : 'NGN';
        $this->ledger->recordStepCredit(
          $execution,
          $step,
          $user,
          $stepAmount,
          $currency,
          $action->label ?? $action->action_type,
          $result['rail_reference']
        );

        // ── Record fee per step ───────────────────────────────────
        $this->recordStepFee(
          $user,
          $execution,
          $step,
          $action->action_type,
          (float) $stepAmount,
          $config,
          $bankFeePerStep,
          $feeService
        );

        // ── Update Atlas wallet for crypto ────────────────────────
        if ($action->action_type === 'convert_crypto') {
          $this->updateAtlasWallet(
            $user->id,
            $result['result']['network']      ?? 'trc20',
            $result['result']['token']        ?? 'USDT',
            $result['result']['amount_token'] ?? '0',
            $config['wallet_label']           ?? 'Wallet'
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
        $account->increment('balance', (float) $totalAmount);
        $this->rollback->rollback($execution->fresh());

        activity()->causedBy($user)->performedOn($execution)
          ->withProperties(['step' => $action->step_order, 'error' => $e->getMessage()])
          ->log('execution.failed');

        throw new \RuntimeException(
          "Execution failed at step {$action->step_order} ({$action->label}): " . $e->getMessage()
        );
      }
    }

    // Complete execution
    $execution->update(['status' => 'completed', 'completed_at' => now()]);
    $rule->increment('execution_count');
    $rule->update(['last_triggered_at' => now()]);

    // Auto-generate receipt
    try {
      $receiptService = new ReceiptService();
      $receiptService->generate($execution->fresh(['steps', 'user', 'rule']));
    } catch (\Throwable $e) {
      Log::warning("Receipt generation failed: " . $e->getMessage());
    }

    activity()->causedBy($user)->performedOn($execution)
      ->withProperties(['total_ngn' => $totalAmount, 'steps' => $actions->count()])
      ->log('execution.completed');

    return $execution->fresh(['steps']);
  }

  private function recordStepFee(
    $user,
    $execution,
    $step,
    string $actionType,
    float $stepAmount,
    array $config,
    float $bankFeePerStep,
    FeeService $feeService
  ): void {
    try {
      $fee = match ($actionType) {
        'send_bank' => [
          'fee_type'       => 'fiat_transfer',
          'fee_amount_ngn' => $bankFeePerStep,
          'fee_rate'       => $bankFeePerStep,
          'description'    => "Transfer fee (₦{$bankFeePerStep})",
        ],
        'convert_crypto' => $feeService->cryptoConversionFee(
          $stepAmount,
          $config['token'] ?? 'USDT'
        ),
        'save_piggyvest',
        'save_cowrywise' => null, // no fee
        'pay_bill'       => null, // no fee — biller commission
        default          => null,
      };

      if ($fee && $fee['fee_amount_ngn'] > 0) {
        FeeLedger::create([
          'user_id'            => $user->id,
          'execution_id'       => $execution->id,
          'execution_step_id'  => $step->id,
          'fee_type'           => $fee['fee_type'],
          'transaction_amount' => $stepAmount,
          'fee_amount'         => $fee['fee_amount_ngn'],
          'fee_rate'           => $fee['fee_rate'] ?? 0,
          'currency'           => 'NGN',
          'description'        => $fee['description'],
          'meta'               => $fee,
        ]);
      }
    } catch (\Throwable $e) {
      Log::warning("Fee recording failed for step {$step->id}: " . $e->getMessage());
    }
  }

  private function updateAtlasWallet(
    string $userId,
    string $network,
    string $token,
    string $amountToken,
    string $label
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
      $wallet->wallet_label     = $label;
      $wallet->balance          = bcadd((string)($wallet->balance ?? '0'), $amountToken, 8);
      $wallet->total_deposited  = bcadd((string)($wallet->total_deposited ?? '0'), $amountToken, 8);
      $wallet->last_activity_at = now();
      $wallet->save();
    } catch (\Throwable $e) {
      Log::warning("Atlas wallet update failed: " . $e->getMessage());
    }
  }

  private function resolveAmount(Rule $rule, ConnectedAccount $account): string
  {
    return match ($rule->total_amount_type) {
      'fixed'        => (string) $rule->total_amount,
      'full_balance' => (string) $account->balance,
      'percentage'   => bcdiv(
        bcmul((string) $account->balance, (string) $rule->total_amount, 10),
        '100',
        6
      ),
      default => throw new \RuntimeException('Unknown amount type.'),
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
      default          => throw new \RuntimeException("No adapter for: {$actionType}"),
    };
  }
}

<?php

namespace App\Services\Engine;

use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Services\EncryptionService;
use App\Services\LedgerService;
use App\Services\Rails\FiatRail;
use App\Services\Rails\PiggyVestRail;
use App\Services\Rails\CowrywiseRail;
use App\Services\Rails\CryptoRail;
use App\Services\Rails\BillsRail;
use Illuminate\Support\Facades\DB;

class ExecutionEngine
{
  public function __construct(
    private readonly LedgerService   $ledger,
    private readonly RollbackManager $rollback,
    private readonly EncryptionService $encryption,
  ) {}

  /**
   * Execute a rule.
   * - Validates balance
   * - Creates execution record
   * - Fires each step in order
   * - Rolls back all steps if any fail
   */
  public function execute(Rule $rule, string $triggeredBy = 'manual'): RuleExecution
  {
    $user    = $rule->user;
    $account = $rule->connectedAccount;

    // Resolve total NGN amount to debit
    $totalAmount = $this->resolveAmount($rule, $account);

    // Validate sufficient balance
    if ((float) $account->balance < (float) $totalAmount) {
      throw new \RuntimeException(
        "Insufficient balance. Available: ₦" . number_format($account->balance, 2) .
          ", Required: ₦" . number_format((float)$totalAmount, 2)
      );
    }

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

    // Record initial debit in ledger
    $this->ledger->recordDebit(
      $execution,
      $user,
      $totalAmount,
      "Rule: {$rule->name} — debited from {$account->institution_name}"
    );

    // Deduct from account balance
    $account->decrement('balance', (float) $totalAmount);

    // Execute each action step in order
    $actions = $rule->actions()->orderBy('step_order')->get();

    foreach ($actions as $action) {
      $stepAmount = $action->resolveAmount($totalAmount);

      $step = ExecutionStep::create([
        'execution_id'  => $execution->id,
        'rule_action_id' => $action->id,
        'step_order'    => $action->step_order,
        'action_type'   => $action->action_type,
        'label'         => $action->label,
        'amount_ngn'    => $stepAmount,
        'status'        => 'running',
        'started_at'    => now(),
      ]);

      try {
        $adapter = $this->resolveAdapter($action->action_type);
        $result  = $adapter->execute($action->config, $stepAmount);

        // Mark step complete and store rollback payload immediately
        $step->update([
          'status'           => 'completed',
          'rail_reference'   => $result['rail_reference'],
          'result'           => $result['result'],
          'rollback_payload' => $result['rollback_payload'],
          'completed_at'     => now(),
        ]);

        // Write ledger credit
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
      } catch (\Throwable $e) {
        // Mark step failed
        $step->update([
          'status'        => 'failed',
          'error_message' => $e->getMessage(),
          'completed_at'  => now(),
        ]);

        // Mark execution failed
        $execution->update([
          'status'        => 'failed',
          'error_message' => "Step {$action->step_order} failed: " . $e->getMessage(),
          'completed_at'  => now(),
        ]);

        // Restore account balance
        $account->increment('balance', (float) $totalAmount);

        // Roll back all completed steps
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

    // All steps completed
    $execution->update([
      'status'       => 'completed',
      'completed_at' => now(),
    ]);

    // Update rule metadata
    $rule->increment('execution_count');
    $rule->update(['last_triggered_at' => now()]);

    activity()
      ->causedBy($user)
      ->performedOn($execution)
      ->withProperties(['total_ngn' => $totalAmount, 'steps' => $actions->count()])
      ->log('execution.completed');

    return $execution->fresh(['steps']);
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
      'pay_bill'       => new BillsRail(),
      default          => throw new \RuntimeException("No adapter for: {$actionType}"),
    };
  }
}

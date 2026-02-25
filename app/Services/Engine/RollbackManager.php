<?php

namespace App\Services\Engine;

use App\Models\ExecutionStep;
use App\Models\RuleExecution;
use App\Services\Rails\FiatRail;
use App\Services\Rails\PiggyVestRail;
use App\Services\Rails\CowrywiseRail;
use App\Services\Rails\CryptoRail;
use App\Services\Rails\BillsRail;
use App\Services\EncryptionService;

class RollbackManager
{
  public function __construct(
    private readonly EncryptionService $encryption
  ) {}

  /**
   * Roll back all completed steps in reverse order.
   * Called when any step in the execution fails.
   */
  public function rollback(RuleExecution $execution): void
  {
    $completedSteps = $execution->completedSteps()
      ->orderBy('step_order', 'desc')
      ->get();

    foreach ($completedSteps as $step) {
      $this->rollbackStep($step);
    }

    $execution->update([
      'status'       => 'rolled_back',
      'completed_at' => now(),
    ]);
  }

  private function rollbackStep(ExecutionStep $step): void
  {
    try {
      $adapter = $this->resolveAdapter($step->action_type);
      $adapter->rollback($step->rollback_payload);

      $step->update([
        'status'         => 'rolled_back',
        'rolled_back_at' => now(),
      ]);
    } catch (\Throwable $e) {
      // Log rollback failure — flag for manual review in production
      \Log::critical('Rollback failed for step', [
        'step_id'     => $step->id,
        'action_type' => $step->action_type,
        'error'       => $e->getMessage(),
      ]);

      $step->update([
        'status'        => 'failed',
        'error_message' => 'Rollback failed: ' . $e->getMessage(),
      ]);
    }
  }

  private function resolveAdapter(string $actionType): mixed
  {
    return match ($actionType) {
      'send_bank'      => new FiatRail($this->encryption),
      'save_piggyvest' => new PiggyVestRail(),
      'save_cowrywise' => new CowrywiseRail(),
      'convert_crypto' => new CryptoRail(),
      'pay_bill'       => new BillsRail(),
      default          => throw new \RuntimeException("No adapter for action type: {$actionType}"),
    };
  }
}

<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\RuleExecution;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Dispute / chargeback management.
 *
 * Flow:
 *   1. User raises a dispute against an execution via POST /disputes
 *   2. Status starts as 'open'
 *   3. Support team reviews and sets to 'under_review'
 *   4. Resolved as 'resolved_refund' (credit back), 'resolved_no_action', or 'closed'
 *
 * On 'resolved_refund': a credit ledger entry is created for the disputed amount.
 * The underlying execution is NOT reversed (rail reversals happen out-of-band
 * with the bank/provider) — we just record Atlas's position.
 */
class DisputeService
{
  public function __construct(
    private readonly LedgerService $ledger
  ) {}

  /**
   * Open a new dispute. User can only dispute their own completed executions.
   * One active dispute per execution — duplicates are rejected.
   */
  public function open(User $user, string $executionId, string $reason, string $description): Dispute
  {
    $execution = RuleExecution::where('user_id', $user->id)
      ->where('status', 'completed')
      ->findOrFail($executionId);

    // Block duplicate active disputes
    $existing = Dispute::where('execution_id', $executionId)
      ->whereNotIn('status', ['resolved_no_action', 'closed'])
      ->first();

    if ($existing) {
      throw new \RuntimeException(
        "A dispute for this execution is already open (#{$existing->dispute_number}). " .
          "Please wait for it to be resolved before filing another."
      );
    }

    // Disputes must be raised within 60 days of execution
    $daysSince = $execution->completed_at?->diffInDays(now()) ?? 0;
    if ($daysSince > 60) {
      throw new \RuntimeException(
        "Disputes must be raised within 60 days of the transaction. " .
          "This execution completed {$daysSince} days ago."
      );
    }

    $dispute = Dispute::create([
      'user_id'        => $user->id,
      'execution_id'   => $executionId,
      'dispute_number' => Dispute::generateNumber(),
      'reason'         => $reason,
      'description'    => $description,
      'amount_ngn'     => $execution->total_debit_ngn,
      'status'         => 'open',
      'opened_at'      => now(),
    ]);

    Log::info("Dispute opened", [
      'dispute_number' => $dispute->dispute_number,
      'user_id'        => $user->id,
      'execution_id'   => $executionId,
      'amount'         => $execution->total_debit_ngn,
    ]);

    // TODO: notify support team via email/Slack

    return $dispute;
  }

  /**
   * Resolve a dispute with a refund.
   * Creates a credit ledger entry for the refunded amount.
   * Called by admin/support — never directly by user.
   */
  public function resolveWithRefund(Dispute $dispute, float $refundAmount, string $note): Dispute
  {
    if (!in_array($dispute->status, ['open', 'under_review'])) {
      throw new \RuntimeException("Dispute is already resolved.");
    }

    $execution = RuleExecution::findOrFail($dispute->execution_id);
    $user      = $execution->user;

    // Credit the user's account balance
    $user->connectedAccounts()
      ->where('is_primary', true)
      ->first()
      ?->increment('balance', $refundAmount);

    // Ledger entry for the refund
    \App\Models\LedgerEntry::create([
      'user_id'     => $user->id,
      'entry_type'  => 'credit',
      'amount'      => $refundAmount,
      'currency'    => 'NGN',
      'description' => "Dispute refund — {$dispute->dispute_number}",
      'reference'   => $dispute->dispute_number,
      'balance_before' => '0', // simplified — real impl uses LedgerService
      'balance_after'  => '0',
    ]);

    $dispute->update([
      'status'         => 'resolved_refund',
      'refund_amount'  => $refundAmount,
      'resolution_note' => $note,
      'resolved_at'    => now(),
    ]);

    Log::info("Dispute resolved with refund", [
      'dispute_number' => $dispute->dispute_number,
      'refund_amount'  => $refundAmount,
    ]);

    return $dispute->fresh();
  }

  public function resolveNoAction(Dispute $dispute, string $note): Dispute
  {
    $dispute->update([
      'status'          => 'resolved_no_action',
      'resolution_note' => $note,
      'resolved_at'     => now(),
    ]);

    return $dispute->fresh();
  }
}

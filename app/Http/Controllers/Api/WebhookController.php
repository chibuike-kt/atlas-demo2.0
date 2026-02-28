<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController
 *
 * Handles inbound callbacks from Mono (account/balance events)
 * and VTPass (bill payment confirmations).
 *
 * Signature verification is handled BEFORE this controller runs
 * by VerifyWebhookSignature middleware. By the time we're here,
 * the payload is trusted.
 */
class WebhookController extends Controller
{
  /** POST /api/webhooks/mono */
  public function mono(Request $request): JsonResponse
  {
    $event = $request->input('event');
    $data  = $request->input('data', []);

    Log::info("Mono webhook received", ['event' => $event]);

    match ($event) {
      'mono.events.account_updated',
      'mono.events.account_connected' => $this->handleMonoAccountUpdate($data),

      'mono.events.reauthorisation_required' => $this->handleMonoReauth($data),

      default => Log::info("Mono webhook unhandled event: {$event}"),
    };

    // Mono expects a 200 response quickly — always return 200
    return response()->json(['received' => true]);
  }

  /** POST /api/webhooks/vtpass */
  public function vtpass(Request $request): JsonResponse
  {
    $requestId = $request->input('requestId');
    $status    = $request->input('content.transactions.status');
    $amount    = $request->input('content.transactions.total_amount');

    Log::info("VTPass webhook received", [
      'request_id' => $requestId,
      'status'     => $status,
      'amount'     => $amount,
    ]);

    // Match to an execution step by rail_reference
    if ($requestId && $status === 'delivered') {
      \App\Models\ExecutionStep::where('rail_reference', $requestId)
        ->where('action_type', 'pay_bill')
        ->where('status', 'running') // only update if still pending
        ->update([
          'status'       => 'completed',
          'completed_at' => now(),
        ]);
    }

    return response()->json(['received' => true]);
  }

  private function handleMonoAccountUpdate(array $data): void
  {
    $monoId = $data['account']['_id'] ?? null;
    if (!$monoId) return;

    $account = ConnectedAccount::where('mono_account_id', $monoId)->first();
    if (!$account) return;

    // Update balance from Mono event payload if present
    $balance = $data['account']['balance'] ?? null;
    if ($balance !== null) {
      $account->update([
        'balance'           => (float) $balance / 100, // Mono sends kobo
        'balance_synced_at' => now(),
      ]);
      Log::info("Balance updated via Mono webhook", [
        'account_id' => $account->id,
        'balance'    => $balance,
      ]);
    }
  }

  private function handleMonoReauth(array $data): void
  {
    $monoId = $data['account']['_id'] ?? null;
    if (!$monoId) return;

    ConnectedAccount::where('mono_account_id', $monoId)
      ->update(['is_active' => false]);

    Log::warning("Mono reauth required — account deactivated", ['mono_id' => $monoId]);
    // TODO: notify user to re-link their account
  }
}

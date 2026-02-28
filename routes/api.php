<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DisputeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\RuleController;
use App\Http\Controllers\Api\RuleParserController;
use Illuminate\Support\Facades\Route;

// ── Auth (public) — strict rate limit: 5/min per IP ──────────────────────
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
  Route::post('/register', [AuthController::class, 'register']);
  Route::post('/login',    [AuthController::class, 'login']);
  Route::post('/refresh',  [AuthController::class, 'refresh']);
});

// ── Auth (protected) ──────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->prefix('auth')->group(function () {
  Route::post('/logout', [AuthController::class, 'logout']);
  Route::get('/me',      [AuthController::class, 'me']);
});

// ── Dashboard ─────────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])
  ->get('/dashboard', [DashboardController::class, 'index']);

// ── Rules ─────────────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->prefix('rules')->group(function () {
  Route::get('/',             [RuleController::class, 'index']);
  Route::post('/',            [RuleController::class, 'store']);
  Route::get('/{id}',         [RuleController::class, 'show']);
  Route::put('/{id}',         [RuleController::class, 'update']);
  Route::delete('/{id}',      [RuleController::class, 'destroy']);
  Route::post('/{id}/toggle', [RuleController::class, 'toggle']);
});

// ── Rule parser ───────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])
  ->post('/rules/parse', [RuleParserController::class, 'parse']);

// ── Executions — tighter rate limit (throttle:execution = 10/min) ─────────
// The execution:throttle guard is the per-minute burst limit.
// VelocityService inside ExecutionController handles hourly/daily limits.
Route::middleware(['auth:api', 'throttle:execution'])->group(function () {
  Route::post('/rules/{id}/execute', [ExecutionController::class, 'execute']);
});

Route::middleware(['auth:api', 'throttle:api'])->group(function () {
  Route::get('/executions',      [ExecutionController::class, 'index']);
  Route::get('/executions/{id}', [ExecutionController::class, 'show']);
  Route::get('/ledger',          [ExecutionController::class, 'ledger']);
});

// ── Accounts ──────────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->prefix('accounts')->group(function () {
  Route::get('/',                  [AccountController::class, 'index']);
  Route::post('/connect',          [AccountController::class, 'connect']);
  Route::post('/{id}/sync',        [AccountController::class, 'sync']);
  Route::get('/{id}/transactions', [AccountController::class, 'transactions']);
});

// ── Contacts ──────────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->prefix('contacts')->group(function () {
  Route::get('/',        [ContactController::class, 'index']);
  Route::post('/',       [ContactController::class, 'store']);
  Route::delete('/{id}', [ContactController::class, 'destroy']);
  Route::get('/device',  [ContactController::class, 'deviceContacts']);
  Route::get('/banks',   [ContactController::class, 'banks']);
});

// ── Receipts ─────────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->group(function () {
  Route::get('/receipts', function () {
    $receipts = \App\Models\Receipt::where('user_id', auth()->id())
      ->orderBy('created_at', 'desc')
      ->get()
      ->map(fn($r) => [
        'id'             => $r->id,
        'receipt_number' => $r->receipt_number,
        'execution_id'   => $r->execution_id,
        'rule_name'      => $r->receipt_data['rule']['name'] ?? '—',
        'total_amount'   => '₦' . number_format((float) $r->total_amount, 2),
        // total_fees is internal — not exposed in list view
        'status'         => $r->status,
        'created_at'     => $r->created_at,
      ]);
    return response()->json(['success' => true, 'data' => $receipts]);
  });

  Route::get('/executions/{id}/receipt', function (string $id) {
    $execution = \App\Models\RuleExecution::where('user_id', auth()->id())
      ->with(['steps', 'rule' => fn($q) => $q->withTrashed(), 'user'])
      ->findOrFail($id);

    $service = new \App\Services\ReceiptService();
    $receipt = $service->generate($execution);

    return response($service->renderHtml($receipt))
      ->header('Content-Type', 'text/html')
      ->header('X-Frame-Options', 'SAMEORIGIN');
  });
});

// ── Disputes ──────────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->prefix('disputes')->group(function () {
  Route::get('/',        [DisputeController::class, 'index']);
  Route::post('/',       [DisputeController::class, 'store']);
  Route::get('/reasons', [DisputeController::class, 'reasons']);
  Route::get('/{id}',    [DisputeController::class, 'show']);
});

// ── Crypto balances ───────────────────────────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->get('/crypto-balances', function () {
  $balances = \App\Models\AtlasWallet::where('user_id', auth()->id())
    ->orderBy('balance', 'desc')
    ->get()
    ->map(fn($w) => [
      'id'             => $w->id,
      'token'          => $w->token,
      'network'        => $w->network,
      'wallet_label'   => $w->wallet_label,
      'balance'        => (float) $w->balance,
      'total_received' => (float) ($w->total_deposited ?? 0),
      'address'        => $w->address,
      'last_activity_at' => $w->last_activity_at,
    ]);
  return response()->json(['success' => true, 'data' => $balances]);
});

// ── Fee summary (for dashboard USDT card) ────────────────────────────────
Route::middleware(['auth:api', 'throttle:api'])->get('/fees/summary', function () {
  // Only return the total — never itemise fee types to the user
  $thisMonth = \App\Models\FeeLedger::where('user_id', auth()->id())
    ->whereMonth('created_at', now()->month)
    ->whereYear('created_at', now()->year)
    ->sum('fee_amount');

  return response()->json([
    'success' => true,
    'data'    => ['this_month' => '₦' . number_format((float) $thisMonth, 2)],
  ]);
});

// ── Webhooks (public but signature-verified) ──────────────────────────────
// No auth:api — these come from Mono/VTPass servers, not our users.
// Signature verification in VerifyWebhookSignature middleware replaces auth.
Route::post('/webhooks/mono',   [\App\Http\Controllers\Api\WebhookController::class, 'mono'])
  ->middleware('webhook.verify:mono');
Route::post('/webhooks/vtpass', [\App\Http\Controllers\Api\WebhookController::class, 'vtpass'])
  ->middleware('webhook.verify:vtpass');

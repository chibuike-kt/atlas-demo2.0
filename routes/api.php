<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\RuleController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\RuleParserController;

Route::middleware('auth:api')->get('/executions/{id}/receipt', function (string $id) {
  $execution = \App\Models\RuleExecution::where('user_id', auth()->id())
    ->with(['steps', 'rule' => fn($q) => $q->withTrashed(), 'user'])
    ->findOrFail($id);

  $service = new \App\Services\ReceiptService();
  $receipt = $service->generate($execution);

  // Return HTML receipt for browser rendering / printing
  return response($service->renderHtml($receipt))
    ->header('Content-Type', 'text/html');
});

// List user receipts
Route::middleware('auth:api')->get('/receipts', function () {
  $receipts = \App\Models\Receipt::where('user_id', auth()->id())
    ->orderBy('created_at', 'desc')
    ->get()
    ->map(fn($r) => [
      'id'             => $r->id,
      'receipt_number' => $r->receipt_number,
      'execution_id'   => $r->execution_id,
      'total_amount'   => '₦' . number_format((float) $r->total_amount, 2),
      'total_fees'     => '₦' . number_format((float) $r->total_fees, 2),
      'status'         => $r->status,
      'created_at'     => $r->created_at,
    ]);

  return response()->json(['success' => true, 'data' => $receipts]);
});

Route::middleware('auth:api')->post('/rules/parse', [RuleParserController::class, 'parse']);

Route::middleware('auth:api')->get('/crypto-balances', function () {
  $balances = \App\Models\CryptoBalance::where('user_id', auth()->id())
    ->orderBy('balance', 'desc')
    ->get()
    ->map(fn($b) => [
      'id'             => $b->id,
      'token'          => $b->token,
      'network'        => $b->network,
      'wallet_label'   => $b->wallet_label,
      'balance'        => (float) $b->balance,
      'balance_formatted' => $b->formattedBalance(),
      'total_received' => (float) $b->total_received,
      'total_sent'     => (float) $b->total_sent,
      'last_updated_at' => $b->last_updated_at,
    ]);

  return response()->json(['success' => true, 'data' => $balances]);
});

// ── Dashboard (protected) ──────────────────────────────────────────────────
Route::middleware('auth:api')->get('/dashboard', [DashboardController::class, 'index']);

// ── Executions (protected) ─────────────────────────────────────────────────
Route::middleware('auth:api')->group(function () {
  Route::post('/rules/{id}/execute', [ExecutionController::class, 'execute']);
  Route::get('/executions',          [ExecutionController::class, 'index']);
  Route::get('/executions/{id}',     [ExecutionController::class, 'show']);
  Route::get('/ledger',              [ExecutionController::class, 'ledger']);
});

// ── Rules (protected) ──────────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('rules')->group(function () {
  Route::get('/',          [RuleController::class, 'index']);
  Route::post('/',         [RuleController::class, 'store']);
  Route::get('/{id}',      [RuleController::class, 'show']);
  Route::put('/{id}',      [RuleController::class, 'update']);
  Route::delete('/{id}',   [RuleController::class, 'destroy']);
  Route::post('/{id}/toggle', [RuleController::class, 'toggle']);
});

// ── Contacts (protected) ───────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('contacts')->group(function () {
  Route::get('/',        [ContactController::class, 'index']);
  Route::post('/',       [ContactController::class, 'store']);
  Route::delete('/{id}', [ContactController::class, 'destroy']);
  Route::get('/device',  [ContactController::class, 'deviceContacts']);
  Route::get('/banks',   [ContactController::class, 'banks']);
});

// ── Accounts (protected) ───────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('accounts')->group(function () {
  Route::get('/',                  [AccountController::class, 'index']);
  Route::post('/connect',          [AccountController::class, 'connect']);
  Route::post('/{id}/sync',        [AccountController::class, 'sync']);
  Route::get('/{id}/transactions', [AccountController::class, 'transactions']);
});

// ── Auth (public) ──────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
  Route::post('/register', [AuthController::class, 'register']);
  Route::post('/login',    [AuthController::class, 'login']);
  Route::post('/refresh',  [AuthController::class, 'refresh']);
});

// ── Auth (protected) ───────────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('auth')->group(function () {
  Route::post('/logout', [AuthController::class, 'logout']);
  Route::get('/me',      [AuthController::class, 'me']);
});

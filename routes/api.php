<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ContactController;

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

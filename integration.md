# Atlas Security Layer — Integration Guide

## Files to copy

| Source file | Destination |
|-------------|-------------|
| `IdempotencyService.php` | `app/Services/Security/IdempotencyService.php` |
| `VelocityService.php` | `app/Services/Security/VelocityService.php` |
| `StepRetryService.php` | `app/Services/Security/StepRetryService.php` |
| `DisputeService.php` | `app/Services/DisputeService.php` |
| `Dispute.php` (model) | `app/Models/Dispute.php` |
| `DisputeController.php` | `app/Http/Controllers/Api/DisputeController.php` |
| `ExecutionController.php` | `app/Http/Controllers/Api/ExecutionController.php` (replace) |
| `VerifyWebhookSignature.php` | `app/Http/Middleware/VerifyWebhookSignature.php` |
| `EnforceTokenFingerprint.php` | `app/Http/Middleware/EnforceTokenFingerprint.php` |
| `2025_01_01_000011_create_disputes_table.php` | `database/migrations/` |

---

## Step 1 — Run migration

```bash
php artisan migrate
```

---

## Step 2 — Register middleware in bootstrap/app.php (Laravel 11)

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'webhook.verify' => \App\Http\Middleware\VerifyWebhookSignature::class,
        'token.fingerprint' => \App\Http\Middleware\EnforceTokenFingerprint::class,
    ]);
})
```

If you're on Laravel 10, add to `app/Http/Kernel.php` instead:
```php
protected $middlewareAliases = [
    // ... existing
    'webhook.verify'     => \App\Http\Middleware\VerifyWebhookSignature::class,
    'token.fingerprint'  => \App\Http\Middleware\EnforceTokenFingerprint::class,
];
```

---

## Step 3 — Add routes to api.php

```php
// ── Disputes ──────────────────────────────────────────────────────────────
Route::middleware('auth:api')->prefix('disputes')->group(function () {
    Route::get('/',         [DisputeController::class, 'index']);
    Route::post('/',        [DisputeController::class, 'store']);
    Route::get('/reasons',  [DisputeController::class, 'reasons']);
    Route::get('/{id}',     [DisputeController::class, 'show']);
});

// ── Webhooks (public but signature-verified) ───────────────────────────────
Route::post('/webhooks/mono',   [WebhookController::class, 'mono'])
    ->middleware('webhook.verify:mono');
Route::post('/webhooks/vtpass', [WebhookController::class, 'vtpass'])
    ->middleware('webhook.verify:vtpass');

// ── Fee summary (for dashboard) ────────────────────────────────────────────
Route::middleware('auth:api')->get('/fees/summary', function () {
    $thisMonth = \App\Models\FeeLedger::where('user_id', auth()->id())
        ->whereMonth('created_at', now()->month)
        ->whereYear('created_at', now()->year)
        ->sum('fee_amount');
    return response()->json([
        'success' => true,
        'data'    => ['this_month' => '₦' . number_format($thisMonth, 2)],
    ]);
});
```

---

## Step 4 — Wire retry into ExecutionEngine

In `ExecutionEngine.php`, inject `StepRetryService` and wrap each rail call:

```php
// Add to constructor:
private readonly StepRetryService $retry,

// In the step loop, replace:
$result = $adapter->execute($config, (float) $stepAmountNgn);

// With:
$result = $this->retry->execute(
    fn() => $adapter->execute($config, (float) $stepAmountNgn),
    $action->label ?? $action->action_type
);
```

Laravel's service container will auto-inject `StepRetryService` if you add it to the constructor — no service provider changes needed.

---

## Step 5 — Wire token fingerprint into AuthController

In `AuthController::login()`, after issuing the token:

```php
// Get the JTI from the new token
$payload = auth('api')->payload();
\App\Http\Middleware\EnforceTokenFingerprint::store($request, $payload->get('jti'));
```

In `AuthController::logout()`:

```php
$payload = auth('api')->payload();
\App\Http\Middleware\EnforceTokenFingerprint::invalidate($payload->get('jti'));
```

Then add `token.fingerprint` to your `auth:api` middleware group or apply per route.

---

## Step 6 — Add webhook secrets to config/services.php

```php
'mono' => [
    'secret_key'     => env('MONO_SECRET_KEY'),
    'webhook_secret' => env('MONO_WEBHOOK_SECRET'),
    'base_url'       => env('MONO_BASE_URL', 'https://api.withmono.com'),
],

'vtpass' => [
    'api_key'        => env('VTPASS_API_KEY'),
    'secret_key'     => env('VTPASS_SECRET_KEY'),
    'base_url'       => env('VTPASS_BASE_URL', 'https://sandbox.vtpass.com/api'),
],
```

Add to `.env`:
```
MONO_WEBHOOK_SECRET=your_mono_webhook_secret_here
VTPASS_SECRET_KEY=your_vtpass_secret_key_here
```

---

## Step 7 — Add rate limiting to api.php (Laravel's built-in throttle)

In `RouteServiceProvider.php` or `bootstrap/app.php`:

```php
RateLimiter::for('api', function (Request $request) {
    return $request->user()
        ? Limit::perMinute(60)->by($request->user()->id)
        : Limit::perMinute(10)->by($request->ip());
});

RateLimiter::for('auth', function (Request $request) {
    // Strict limit on login attempts — 5 per minute per IP
    return Limit::perMinute(5)->by($request->ip())
        ->response(fn() => response()->json([
            'error' => 'Too many login attempts. Try again in 60 seconds.'
        ], 429));
});
```

Apply to auth routes:
```php
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/login', ...);
    Route::post('/register', ...);
});
```

---

## What each layer protects against

| Layer | Attack prevented |
|-------|-----------------|
| Idempotency | Double-debit on network retry |
| Execution lock | Race condition from parallel triggers |
| Velocity checks | Account drain via automated rules |
| Amount anomaly | Large unexpected transfers (account takeover) |
| Step retry | Rollback on transient rail failures |
| Dispute system | Chargeback management |
| Webhook signature | Fake balance/payment notifications |
| Token fingerprint | Stolen JWT used from different device |
| Rate limiting | Brute force login, API abuse |

---

## Recommended next steps (not built yet)

1. **KYC tier limits** — unverified users: max ₦200k/day. BVN-verified: max ₦5M/day. NIN+BVN: unlimited. Enforced in VelocityService.
2. **2FA on large executions** — require OTP for any execution > ₦500k
3. **Admin dashboard** — dispute queue, fee ledger view, velocity violations log
4. **Audit log** — `activity_log` (already using spatie/laravel-activitylog) for all state changes
5. **Encryption at rest** — `rule_snapshot` in `rule_executions` contains sensitive config — encrypt before storing

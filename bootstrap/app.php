<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['api/*']);
        $middleware->statefulApi();

        // Alias registration is safe here — no facade calls, just class binding
        $middleware->alias([
            'webhook.verify'    => \App\Http\Middleware\VerifyWebhookSignature::class,
            'token.fingerprint' => \App\Http\Middleware\EnforceTokenFingerprint::class,
        ]);

        // NOTE: RateLimiter::for() calls are in AppServiceProvider::boot()
        // Facades cannot be called inside withMiddleware — the container
        // isn't fully booted at this point in the bootstrap lifecycle.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for all API exceptions — never HTML error pages
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = match (true) {
                    $e instanceof \Illuminate\Auth\AuthenticationException              => 401,
                    $e instanceof \Illuminate\Auth\Access\AuthorizationException        => 403,
                    $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException  => 404,
                    $e instanceof \Illuminate\Validation\ValidationException            => 422,
                    $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException => $e->getStatusCode(),
                    default                                                             => 500,
                };

                $body = [
                    'success' => false,
                    'message' => $e instanceof \Illuminate\Validation\ValidationException
                        ? $e->validator->errors()->first()
                        : $e->getMessage(),
                ];

                if (app()->environment('local') && $status === 500) {
                    $body['debug'] = $e->getTraceAsString();
                }

                return response()->json($body, $status);
            }
        });
    })->create();

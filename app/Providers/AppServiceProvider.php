<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Rate limiters must be defined here — NOT in bootstrap/app.php.
     * Facades (like RateLimiter) require the container to be fully booted,
     * which hasn't happened yet during the withMiddleware() bootstrap phase.
     */
    private function configureRateLimiting(): void
    {
        // General API — 60 req/min per authenticated user, 10/min per IP for guests
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(60)->by($request->user()->id)
                : Limit::perMinute(10)->by($request->ip());
        });

        // Auth endpoints — strict: 5 attempts/min per IP (brute-force protection)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Execution trigger — 10/min per user (burst guard)
        // VelocityService handles the hourly/daily fraud limits separately
        RateLimiter::for('execution', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(10)->by('exec:' . $request->user()->id)
                : Limit::perMinute(2)->by($request->ip());
        });
    }
}

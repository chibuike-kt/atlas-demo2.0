<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a JWT to the IP + User-Agent it was issued to.
 * A stolen token used from a different device/IP is rejected.
 *
 * Uses php-open-source-saver/jwt-auth — NOT tymon/jwt-auth.
 */
class EnforceTokenFingerprint
{
  private const TTL = 86400; // 24h — matches JWT expiry

  public function handle(Request $request, Closure $next): Response
  {
    $user = auth()->user();
    if (!$user) return $next($request);

    try {
      $payload     = JWTAuth::parseToken()->getPayload();
      $jti         = $payload->get('jti');
      $fingerprint = $this->fingerprint($request);

      if (!$jti) return $next($request);

      $stored = Cache::get("atlas:token_fp:{$jti}");

      if ($stored === null) {
        // First request with this token — store fingerprint
        Cache::put("atlas:token_fp:{$jti}", $fingerprint, self::TTL);
      } elseif (!hash_equals($stored, $fingerprint)) {
        Log::warning('Token fingerprint mismatch — possible token theft', [
          'user_id'    => $user->id,
          'ip'         => $request->ip(),
          'user_agent' => substr($request->userAgent() ?? '', 0, 80),
        ]);

        Cache::forget("atlas:token_fp:{$jti}");

        return response()->json([
          'success' => false,
          'message' => 'Session invalid. Please log in again.',
          'code'    => 'TOKEN_FINGERPRINT_MISMATCH',
        ], 401);
      }
    } catch (\Throwable $e) {
      // Don't block on fingerprint errors — JWT middleware handles invalid tokens
      Log::warning('Token fingerprint check error: ' . $e->getMessage());
    }

    return $next($request);
  }

  private function fingerprint(Request $request): string
  {
    $ua       = $request->userAgent() ?? '';
    $isMobile = str_contains(strtolower($ua), 'mobile')
      || str_contains(strtolower($ua), 'android')
      || str_contains(strtolower($ua), 'iphone');

    // Mobile: UA only (IPs change too often on cellular)
    // Web: IP + UA
    $parts = $isMobile ? [$ua] : [$request->ip(), $ua];

    return hash('sha256', implode('|', $parts));
  }

  public static function store(Request $request, string $jti): void
  {
    $ua       = $request->userAgent() ?? '';
    $isMobile = str_contains(strtolower($ua), 'mobile')
      || str_contains(strtolower($ua), 'android')
      || str_contains(strtolower($ua), 'iphone');

    $parts = $isMobile ? [$ua] : [$request->ip(), $ua];
    Cache::put("atlas:token_fp:{$jti}", hash('sha256', implode('|', $parts)), self::TTL);
  }

  public static function invalidate(string $jti): void
  {
    Cache::forget("atlas:token_fp:{$jti}");
  }
}

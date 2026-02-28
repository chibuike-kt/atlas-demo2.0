<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token fingerprinting — ties a JWT to the IP and User-Agent it was issued to.
 *
 * Problem: If an attacker steals a valid JWT (XSS, MitM, leaked log),
 * they can use it from anywhere. Standard JWT has no built-in device binding.
 *
 * Solution: At login, we store a fingerprint (hash of IP + User-Agent) in
 * cache keyed to the token's JTI (JWT ID). On every subsequent request,
 * we compare the current fingerprint against the stored one.
 *
 * Mismatch = possible token theft = force re-authentication.
 *
 * Limitations:
 * - Mobile users on cellular networks change IPs frequently — use
 *   User-Agent only fingerprint for mobile (detect via User-Agent string)
 * - Corporate proxies can cause false positives — log before blocking in prod
 *
 * Registration in Kernel.php:
 *   'auth:api' middleware group should include this AFTER jwt.auth
 */
class EnforceTokenFingerprint
{
  private const TTL = 86400; // 24h — matches JWT expiry

  public function handle(Request $request, Closure $next): Response
  {
    $user = auth()->user();
    if (!$user) return $next($request);

    // Get the JTI (JWT ID) from the current token
    try {
      $payload     = auth('api')->payload();
      $jti         = $payload->get('jti');
      $fingerprint = $this->fingerprint($request);

      if (!$jti) return $next($request); // token has no JTI — skip

      $storedFingerprint = Cache::get("atlas:token_fp:{$jti}");

      if ($storedFingerprint === null) {
        // First request with this token — store fingerprint
        Cache::put("atlas:token_fp:{$jti}", $fingerprint, self::TTL);
      } elseif (!hash_equals($storedFingerprint, $fingerprint)) {
        // Fingerprint mismatch — possible token theft
        Log::warning("Token fingerprint mismatch — possible token theft", [
          'user_id'            => $user->id,
          'jti'                => $jti,
          'stored_fingerprint' => substr($storedFingerprint, 0, 8) . '...',
          'current_ip'         => $request->ip(),
          'user_agent'         => substr($request->userAgent() ?? '', 0, 80),
        ]);

        // Invalidate the token
        Cache::forget("atlas:token_fp:{$jti}");

        return response()->json([
          'error'   => 'Session invalid. Please log in again.',
          'code'    => 'TOKEN_FINGERPRINT_MISMATCH',
        ], 401);
      }
    } catch (\Throwable $e) {
      // Don't block on fingerprint errors — log and continue
      Log::warning("Token fingerprint check failed: " . $e->getMessage());
    }

    return $next($request);
  }

  /**
   * Generate fingerprint from request context.
   * For mobile: User-Agent only (IPs change too often on cellular)
   * For web: IP + User-Agent
   */
  private function fingerprint(Request $request): string
  {
    $ua      = $request->userAgent() ?? '';
    $isMobile = str_contains(strtolower($ua), 'mobile')
      || str_contains(strtolower($ua), 'android')
      || str_contains(strtolower($ua), 'iphone');

    $components = $isMobile
      ? [$ua]
      : [$request->ip(), $ua];

    return hash('sha256', implode('|', $components));
  }

  /**
   * Store fingerprint at login time. Call this from AuthController::login().
   */
  public static function store(Request $request, string $jti): void
  {
    $ua      = $request->userAgent() ?? '';
    $isMobile = str_contains(strtolower($ua), 'mobile')
      || str_contains(strtolower($ua), 'android')
      || str_contains(strtolower($ua), 'iphone');

    $components = $isMobile ? [$ua] : [$request->ip(), $ua];
    $fingerprint = hash('sha256', implode('|', $components));

    Cache::put("atlas:token_fp:{$jti}", $fingerprint, self::TTL);
  }

  /**
   * Invalidate fingerprint on logout. Call from AuthController::logout().
   */
  public static function invalidate(string $jti): void
  {
    Cache::forget("atlas:token_fp:{$jti}");
  }
}

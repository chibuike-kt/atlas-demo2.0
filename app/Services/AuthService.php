<?php

namespace App\Services;

use App\Http\Middleware\EnforceTokenFingerprint;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * AuthService
 *
 * Uses php-open-source-saver/jwt-auth (NOT tymon/jwt-auth).
 * Facade: PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth
 * Guard driver: 'jwt' (set in config/auth.php)
 *
 * Security layers added:
 *   1. Login lockout  — failed_login_count >= 5 → locked_until 15min
 *   2. Token fingerprint — binds JWT JTI to device at login/refresh
 *   3. Last login IP recorded for audit trail
 */
class AuthService
{
  private const MAX_FAILED_ATTEMPTS = 5;
  private const LOCKOUT_MINUTES     = 15;

  public function __construct(private readonly Request $request) {}

  public function register(array $data): array
  {
    $user = User::create([
      'full_name'       => $data['full_name'],
      'email'           => $data['email'],
      'phone'           => $data['phone'] ?? null,
      'password'        => Hash::make($data['password']),
      'encryption_salt' => bin2hex(random_bytes(32)),
      'role'            => 'user',
      'is_active'       => true,
      'is_verified'     => false,
    ]);

    $token        = JWTAuth::fromUser($user);
    $refreshToken = $this->issueRefreshToken($user);

    Log::info('User registered', ['user_id' => $user->id, 'email' => $user->email]);

    return [
      'user'          => $this->formatUser($user),
      'access_token'  => $token,
      'refresh_token' => $refreshToken,
      'token_type'    => 'bearer',
      'expires_in'    => config('jwt.ttl') * 60,
    ];
  }

  public function login(array $data): array
  {
    $user = User::where('email', $data['email'])->first();

    // Generic message — don't reveal whether email exists
    if (!$user) {
      throw new \RuntimeException('Invalid credentials.', 401);
    }

    if (!$user->is_active) {
      throw new \RuntimeException('Your account has been suspended. Contact support.', 403);
    }

    // Check lockout BEFORE password — avoids timing attacks revealing valid emails
    if ($user->locked_until && $user->locked_until->isFuture()) {
      $minutesLeft = (int) now()->diffInMinutes($user->locked_until, false) + 1;
      throw new \RuntimeException(
        "Account temporarily locked due to too many failed attempts. " .
          "Try again in {$minutesLeft} minute(s).",
        429
      );
    }

    if (!Hash::check($data['password'], $user->password)) {
      $this->handleFailedLogin($user);
      throw new \RuntimeException('Invalid credentials.', 401);
    }

    // Reset lockout state on success
    $user->update([
      'failed_login_count' => 0,
      'locked_until'       => null,
      'last_login_at'      => now(),
      'last_login_ip'      => $this->request->ip(),
    ]);

    $token        = JWTAuth::fromUser($user);
    $refreshToken = $this->issueRefreshToken($user);

    // Bind JWT to this device fingerprint
    try {
      $payload = JWTAuth::setToken($token)->getPayload();
      EnforceTokenFingerprint::store($this->request, $payload->get('jti'));
    } catch (\Throwable $e) {
      Log::warning('Failed to store token fingerprint: ' . $e->getMessage());
    }

    Log::info('User logged in', [
      'user_id' => $user->id,
      'ip'      => $this->request->ip(),
    ]);

    return [
      'user'          => $this->formatUser($user),
      'access_token'  => $token,
      'refresh_token' => $refreshToken,
      'token_type'    => 'bearer',
      'expires_in'    => config('jwt.ttl') * 60,
    ];
  }

  public function refresh(string $refreshToken): array
  {
    $stored = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
      ->where('expires_at', '>', now())
      ->whereNull('revoked_at')
      ->with('user')
      ->firstOrFail();

    $user  = $stored->user;
    $token = JWTAuth::fromUser($user);

    // Rotate refresh token — revoke old, issue new
    $stored->update(['revoked_at' => now()]);
    $newRefreshToken = $this->issueRefreshToken($user);

    try {
      $payload = JWTAuth::setToken($token)->getPayload();
      EnforceTokenFingerprint::store($this->request, $payload->get('jti'));
    } catch (\Throwable $e) {
      Log::warning('Failed to store token fingerprint on refresh: ' . $e->getMessage());
    }

    return [
      'access_token'  => $token,
      'refresh_token' => $newRefreshToken,
      'token_type'    => 'bearer',
      'expires_in'    => config('jwt.ttl') * 60,
    ];
  }

  public function logout(string $refreshToken): void
  {
    RefreshToken::where('token_hash', hash('sha256', $refreshToken))
      ->update(['revoked_at' => now()]);

    try {
      $payload = JWTAuth::parseToken()->getPayload();
      EnforceTokenFingerprint::invalidate($payload->get('jti'));
    } catch (\Throwable $e) {
      // Token may already be expired — not an error
    }

    try {
      JWTAuth::invalidate(JWTAuth::getToken());
    } catch (\Throwable $e) {
      Log::warning('JWT invalidation failed on logout: ' . $e->getMessage());
    }
  }

  // ── Private helpers ────────────────────────────────────────────────────────

  private function handleFailedLogin(User $user): void
  {
    $newCount = ($user->failed_login_count ?? 0) + 1;
    $updates  = ['failed_login_count' => $newCount];

    if ($newCount >= self::MAX_FAILED_ATTEMPTS) {
      $updates['locked_until'] = now()->addMinutes(self::LOCKOUT_MINUTES);
      Log::warning('Account locked after failed attempts', [
        'user_id'  => $user->id,
        'attempts' => $newCount,
        'ip'       => $this->request->ip(),
      ]);
    }

    $user->update($updates);
  }

  private function issueRefreshToken(User $user): string
  {
    $raw    = bin2hex(random_bytes(40)); // 80-char hex token
    $hashed = hash('sha256', $raw);

    RefreshToken::create([
      'user_id'     => $user->id,
      'token_hash'  => $hashed,
      'device_name' => substr($this->request->userAgent() ?? 'unknown', 0, 100),
      'ip_address'  => $this->request->ip(),
      'expires_at'  => now()->addDays(30),
    ]);

    return $raw; // raw sent to client, hash stored in DB
  }

  private function formatUser(User $user): array
  {
    return [
      'id'         => $user->id,
      'name'       => $user->full_name,
      'email'      => $user->email,
      'phone'      => $user->phone,
      'role'       => $user->role,
      'verified'   => (bool) $user->is_verified,
      'created_at' => $user->created_at,
    ];
  }
}

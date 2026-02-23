<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

class AuthService
{
  private const MAX_ATTEMPTS    = 5;
  private const LOCKOUT_MINUTES = 15;
  private const REFRESH_TTL     = 604800; // 7 days in seconds

  public function register(array $data): array
  {
    $user = User::create([
      'full_name'       => $data['full_name'],
      'email'           => strtolower($data['email']),
      'phone'           => $data['phone'] ?? null,
      'password'        => $data['password'],
      'encryption_salt' => bin2hex(random_bytes(32)),
      'role'            => 'user',
    ]);

    activity()
      ->causedBy($user)
      ->performedOn($user)
      ->log('user.registered');

    $token = JWTAuth::fromUser($user);

    return $this->buildAuthResponse($user, $token, $data['device_name'] ?? null);
  }

  public function login(array $data): array
  {
    $user = User::where('email', strtolower($data['email']))->first();

    // User not found — same error as wrong password (prevent enumeration)
    if (!$user) {
      throw new \RuntimeException('Invalid credentials.', 401);
    }

    // Check lockout
    if ($user->isLocked()) {
      $minutes = now()->diffInMinutes($user->locked_until);
      throw new \RuntimeException(
        "Account locked. Try again in {$minutes} minute(s).",
        423
      );
    }

    // Wrong password
    if (!Hash::check($data['password'], $user->password)) {
      $this->recordFailedAttempt($user);
      throw new \RuntimeException('Invalid credentials.', 401);
    }

    // Successful login — reset counters
    $user->update([
      'failed_login_count' => 0,
      'locked_until'       => null,
      'last_login_at'      => now(),
      'last_login_ip'      => request()->ip(),
    ]);

    activity()
      ->causedBy($user)
      ->performedOn($user)
      ->withProperties(['ip' => request()->ip()])
      ->log('user.login');

    $token = JWTAuth::fromUser($user);

    return $this->buildAuthResponse($user, $token, $data['device_name'] ?? null);
  }

  public function refresh(string $refreshToken): array
  {
    $hash   = hash('sha256', $refreshToken);
    $record = RefreshToken::where('token_hash', $hash)->first();

    if (!$record || !$record->isValid()) {
      throw new \RuntimeException('Invalid or expired refresh token.', 401);
    }

    // Rotate — revoke old, issue new
    $record->update(['revoked_at' => now()]);

    $user  = $record->user;
    $token = JWTAuth::fromUser($user);

    return $this->buildAuthResponse($user, $token, $record->device_name);
  }

  public function logout(string $refreshToken): void
  {
    $hash = hash('sha256', $refreshToken);

    RefreshToken::where('token_hash', $hash)
      ->update(['revoked_at' => now()]);

    JWTAuth::invalidate(JWTAuth::getToken());

    activity()->log('user.logout');
  }

  private function buildAuthResponse(User $user, string $accessToken, ?string $deviceName): array
  {
    // Generate refresh token
    $raw  = Str::random(64);
    $hash = hash('sha256', $raw);

    RefreshToken::create([
      'user_id'     => $user->id,
      'token_hash'  => $hash,
      'device_name' => $deviceName,
      'ip_address'  => request()->ip(),
      'expires_at'  => Carbon::now()->addSeconds(self::REFRESH_TTL),
    ]);

    return [
      'access_token'  => $accessToken,
      'refresh_token' => $raw,
      'token_type'    => 'bearer',
      'expires_in'    => config('jwt.ttl') * 60,
      'user'          => [
        'id'       => $user->id,
        'name'     => $user->full_name,
        'email'    => $user->email,
        'phone'    => $user->phone,
        'role'     => $user->role,
        'verified' => $user->is_verified,
      ],
    ];
  }

  private function recordFailedAttempt(User $user): void
  {
    $attempts = $user->failed_login_count + 1;
    $payload  = ['failed_login_count' => $attempts];

    if ($attempts >= self::MAX_ATTEMPTS) {
      $payload['locked_until'] = now()->addMinutes(self::LOCKOUT_MINUTES);

      activity()
        ->causedBy($user)
        ->performedOn($user)
        ->withProperties(['attempts' => $attempts])
        ->log('user.locked');
    }

    $user->update($payload);
  }
}

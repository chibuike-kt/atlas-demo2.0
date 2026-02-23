<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasUuids, HasRoles;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'password',
        'encryption_salt',
        'role',
        'is_active',
        'is_verified',
        'last_login_at',
        'last_login_ip',
        'failed_login_count',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'encryption_salt',
        'failed_login_count',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'locked_until'      => 'datetime',
            'is_active'         => 'boolean',
            'is_verified'       => 'boolean',
            'password'          => 'hashed',
        ];
    }

    // ── JWT ───────────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'email' => $this->email,
            'name'  => $this->full_name,
            'role'  => $this->role,
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    public function savedContacts(): HasMany
    {
        return $this->hasMany(SavedContact::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }

    public function ruleExecutions(): HasMany
    {
        return $this->hasMany(RuleExecution::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function primaryAccount(): ?ConnectedAccount
    {
        return $this->connectedAccounts()->where('is_primary', true)->first();
    }
}

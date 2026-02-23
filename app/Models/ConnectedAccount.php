<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectedAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'mono_account_id',
        'institution_name',
        'institution_code',
        'account_name',
        'account_number_enc',
        'account_type',
        'currency',
        'balance',
        'balance_synced_at',
        'is_primary',
        'is_active',
        'meta',
    ];

    protected $hidden = [
        'account_number_enc',
    ];

    protected function casts(): array
    {
        return [
            'balance'           => 'decimal:6',
            'balance_synced_at' => 'datetime',
            'is_primary'        => 'boolean',
            'is_active'         => 'boolean',
            'meta'              => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }

    public function formattedBalance(): string
    {
        return '₦' . number_format((float) $this->balance, 2);
    }
}

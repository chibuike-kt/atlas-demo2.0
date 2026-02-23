<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedContact extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'label',
        'type',
        'account_name_enc',
        'account_number_enc',
        'bank_code',
        'bank_name',
        'wallet_address_enc',
        'crypto_network',
        'is_active',
    ];

    protected $hidden = [
        'account_name_enc',
        'account_number_enc',
        'wallet_address_enc',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBank(): bool
    {
        return $this->type === 'bank';
    }

    public function isCrypto(): bool
    {
        return $this->type === 'crypto';
    }
}

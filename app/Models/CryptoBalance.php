<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoBalance extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'contact_id',
        'token',
        'network',
        'wallet_label',
        'balance',
        'total_received',
        'total_sent',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'balance'        => 'decimal:8',
            'total_received' => 'decimal:8',
            'total_sent'     => 'decimal:8',
            'last_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(SavedContact::class, 'contact_id');
    }

    public function formattedBalance(): string
    {
        return number_format((float) $this->balance, 4) . ' ' . $this->token;
    }
}

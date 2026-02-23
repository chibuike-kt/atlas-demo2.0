<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    // Ledger is append-only — no updates ever
    public static $readOnly = true;

    protected $fillable = [
        'execution_id',
        'execution_step_id',
        'user_id',
        'entry_type',
        'amount',
        'currency',
        'description',
        'reference',
        'balance_before',
        'balance_after',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:6',
            'balance_before' => 'decimal:6',
            'balance_after'  => 'decimal:6',
            'created_at'     => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function formattedAmount(): string
    {
        $prefix = $this->entry_type === 'credit' ? '+' : '-';
        return $prefix . '₦' . number_format((float) $this->amount, 2);
    }
}

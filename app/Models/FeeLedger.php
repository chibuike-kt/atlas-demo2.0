<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeLedger extends Model
{
    use HasUuids;

    protected $table = 'fee_ledger';

    protected $fillable = [
        'user_id',
        'execution_id',
        'execution_step_id',
        'fee_type',
        'transaction_amount',
        'fee_amount',
        'fee_rate',
        'currency',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'transaction_amount' => 'decimal:6',
            'fee_amount'         => 'decimal:6',
            'fee_rate'           => 'decimal:6',
            'meta'               => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(RuleExecution::class, 'execution_id');
    }
}

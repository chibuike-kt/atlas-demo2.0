<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RuleAction extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'rule_id',
        'step_order',
        'action_type',
        'amount_type',
        'amount',
        'config',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'config' => 'array',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function executionSteps(): HasMany
    {
        return $this->hasMany(ExecutionStep::class);
    }

    public function resolveAmount(string $totalAmount): string
    {
        if ($this->amount_type === 'percentage') {
            return bcdiv(
                bcmul((string) $totalAmount, (string) $this->amount, 10),
                '100',
                6
            );
        }

        return (string) $this->amount;
    }
}

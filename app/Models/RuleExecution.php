<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuleExecution extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'user_id',
        'triggered_by',
        'rule_snapshot',
        'total_amount_ngn',
        'service_charge_ngn',
        'total_debit_ngn',
        'status',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'rule_snapshot'   => 'array',
            'total_amount_ngn' => 'decimal:6',
            'started_at'      => 'datetime',
            'completed_at'    => 'datetime',
            'created_at'      => 'datetime',
            'service_charge_ngn' => 'decimal:6',
            'total_debit_ngn'    => 'decimal:6',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ExecutionStep::class, 'execution_id')
            ->orderBy('step_order');
    }

    public function completedSteps(): HasMany
    {
        return $this->steps()->where('status', 'completed');
    }

    public function isRollbackable(): bool
    {
        return in_array($this->status, ['failed', 'running']);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionStep extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'rule_action_id',
        'step_order',
        'action_type',
        'label',
        'amount_ngn',
        'status',
        'rail_reference',
        'result',
        'rollback_payload',
        'started_at',
        'completed_at',
        'rolled_back_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'amount_ngn'       => 'decimal:6',
            'result'           => 'array',
            'rollback_payload' => 'array',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
            'rolled_back_at'   => 'datetime',
            'created_at'       => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(RuleExecution::class, 'execution_id');
    }

    public function ruleAction(): BelongsTo
    {
        return $this->belongsTo(RuleAction::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasRollbackPayload(): bool
    {
        return !empty($this->rollback_payload);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rule extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'connected_account_id',
        'name',
        'description',
        'trigger_type',
        'trigger_config',
        'total_amount_type',
        'total_amount',
        'currency',
        'on_failure',
        'is_active',
        'last_triggered_at',
        'execution_count',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config'    => 'array',
            'total_amount'      => 'decimal:6',
            'is_active'         => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RuleAction::class)->orderBy('step_order');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(RuleExecution::class);
    }

    public function snapshot(): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'trigger_type'       => $this->trigger_type,
            'trigger_config'     => $this->trigger_config,
            'total_amount_type'  => $this->total_amount_type,
            'total_amount'       => $this->total_amount,
            'currency'           => $this->currency,
            'on_failure'         => $this->on_failure,
            'actions'            => $this->actions->map(fn($a) => [
                'id'          => $a->id,
                'step_order'  => $a->step_order,
                'action_type' => $a->action_type,
                'amount_type' => $a->amount_type,
                'amount'      => $a->amount,
                'config'      => $a->config,
                'label'       => $a->label,
            ])->toArray(),
        ];
    }
}

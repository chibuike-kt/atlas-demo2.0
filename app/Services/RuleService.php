<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\Rule;
use App\Models\RuleAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RuleService
{
  /**
   * Create a rule with its ordered actions in a single transaction.
   */
  public function create(User $user, array $data): Rule
  {
    return DB::transaction(function () use ($user, $data) {

      $rule = Rule::create([
        'user_id'              => $user->id,
        'connected_account_id' => $data['connected_account_id'],
        'name'                 => $data['name'],
        'description'          => $data['description'] ?? null,
        'trigger_type'         => $data['trigger_type'],
        'trigger_config'       => $data['trigger_config'],
        'total_amount_type'    => $data['total_amount_type'],
        'total_amount'         => $data['total_amount'] ?? null,
        'currency'             => 'NGN',
        'on_failure'           => 'rollback',
        'is_active'            => true,
      ]);

      foreach ($data['actions'] as $index => $action) {
        RuleAction::create([
          'rule_id'     => $rule->id,
          'step_order'  => $index + 1,
          'action_type' => $action['action_type'],
          'amount_type' => $action['amount_type'],
          'amount'      => $action['amount'],
          'config'      => $action['config'],
          'label'       => $action['label'] ?? null,
        ]);
      }

      activity()
        ->causedBy($user)
        ->performedOn($rule)
        ->log('rule.created');

      return $rule->load('actions', 'connectedAccount');
    });
  }

  public function update(User $user, string $id, array $data): Rule
  {
    return DB::transaction(function () use ($user, $id, $data) {

      $rule = $user->rules()->findOrFail($id);

      $rule->update([
        'name'                 => $data['name'] ?? $rule->name,
        'description'          => $data['description'] ?? $rule->description,
        'connected_account_id' => $data['connected_account_id'] ?? $rule->connected_account_id,
        'trigger_type'         => $data['trigger_type'] ?? $rule->trigger_type,
        'trigger_config'       => $data['trigger_config'] ?? $rule->trigger_config,
        'total_amount_type'    => $data['total_amount_type'] ?? $rule->total_amount_type,
        'total_amount'         => $data['total_amount'] ?? $rule->total_amount,
      ]);

      if (!empty($data['actions'])) {
        // Replace all actions
        $rule->actions()->delete();

        foreach ($data['actions'] as $index => $action) {
          RuleAction::create([
            'rule_id'     => $rule->id,
            'step_order'  => $index + 1,
            'action_type' => $action['action_type'],
            'amount_type' => $action['amount_type'],
            'amount'      => $action['amount'],
            'config'      => $action['config'],
            'label'       => $action['label'] ?? null,
          ]);
        }
      }

      activity()
        ->causedBy($user)
        ->performedOn($rule)
        ->log('rule.updated');

      return $rule->fresh(['actions', 'connectedAccount']);
    });
  }

  public function delete(User $user, string $id): void
  {
    $rule = $user->rules()->findOrFail($id);

    activity()
      ->causedBy($user)
      ->performedOn($rule)
      ->log('rule.deleted');

    $rule->actions()->delete();
    $rule->delete();
  }

  public function toggle(User $user, string $id): Rule
  {
    $rule = $user->rules()->findOrFail($id);
    $rule->update(['is_active' => !$rule->is_active]);

    activity()
      ->causedBy($user)
      ->performedOn($rule)
      ->withProperties(['is_active' => $rule->is_active])
      ->log($rule->is_active ? 'rule.activated' : 'rule.deactivated');

    return $rule->fresh(['actions', 'connectedAccount']);
  }

  public function format(Rule $rule): array
  {
    $account = $rule->connectedAccount;

    return [
      'id'          => $rule->id,
      'name'        => $rule->name,
      'description' => $rule->description,
      'source_account' => $account ? [
        'id'          => $account->id,
        'institution' => $account->institution_name,
        'balance'     => $account->formattedBalance(),
        'is_primary'  => $account->is_primary,
      ] : null,
      'trigger'     => [
        'type'   => $rule->trigger_type,
        'config' => $rule->trigger_config,
        'label'  => $this->triggerLabel($rule),
      ],
      'total_amount' => [
        'type'  => $rule->total_amount_type,
        'value' => $rule->total_amount,
        'label' => $this->amountLabel($rule),
      ],
      'actions'         => $rule->actions->map(fn($a) => [
        'id'          => $a->id,
        'step'        => $a->step_order,
        'type'        => $a->action_type,
        'label'       => $a->label,
        'amount_type' => $a->amount_type,
        'amount'      => $a->amount,
        'config'      => $a->config,
      ])->toArray(),
      'on_failure'      => $rule->on_failure,
      'is_active'       => $rule->is_active,
      'execution_count' => $rule->execution_count,
      'last_triggered'  => $rule->last_triggered_at,
    ];
  }

  private function triggerLabel(Rule $rule): string
  {
    return match ($rule->trigger_type) {
      'schedule' => 'Every month on day ' . ($rule->trigger_config['day'] ?? '?'),
      'deposit'  => 'When salary/deposit arrives',
      'balance'  => 'When balance exceeds ₦' . number_format($rule->trigger_config['min_amount'] ?? 0),
      'manual'   => 'Manual trigger',
      default    => $rule->trigger_type,
    };
  }

  private function amountLabel(Rule $rule): string
  {
    return match ($rule->total_amount_type) {
      'fixed'       => '₦' . number_format((float)$rule->total_amount),
      'percentage'  => $rule->total_amount . '% of balance',
      'full_balance' => 'Full available balance',
      default       => '',
    };
  }
}

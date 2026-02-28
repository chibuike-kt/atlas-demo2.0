<?php

namespace App\Services\Engine;

use App\Models\AtlasWallet;
use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;
use App\Models\FeeLedger;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Services\EncryptionService;
use App\Services\FeeService;
use App\Services\LedgerService;
use App\Services\ReceiptService;
use App\Services\Security\StepRetryService;
use App\Services\Rails\BillRail;
use App\Services\Rails\CowrywiseRail;
use App\Services\Rails\CryptoRail;
use App\Services\Rails\FiatRail;
use App\Services\Rails\PiggyVestRail;
use Illuminate\Support\Facades\Log;

class ExecutionEngine
{
    public function __construct(
        private readonly LedgerService     $ledger,
        private readonly RollbackManager   $rollback,
        private readonly EncryptionService $encryption,
        private readonly StepRetryService  $retry,   // NEW — injected by container
    ) {}

    public function execute(Rule $rule, string $triggeredBy = 'manual'): RuleExecution
    {
        $user       = $rule->user;
        $account    = $rule->connectedAccount;
        $feeService = new FeeService();

        $actions        = $rule->actions()->orderBy('step_order')->get();
        $totalStepCount = $actions->count();

        $movementAmount = $this->resolveMovementAmount($rule, $account);

        $execFeeData   = $feeService->executionFee($totalStepCount);
        $execFeeNgn    = (string) $execFeeData['fee_amount_ngn'];
        $totalDebit    = bcadd($movementAmount, $execFeeNgn, 6);

        // Balance check
        if (bccomp((string) $account->balance, $totalDebit, 2) < 0) {
            $needed    = number_format((float) $totalDebit, 2);
            $available = number_format((float) $account->balance, 2);
            $charge    = number_format((float) $execFeeNgn, 2);
            $movement  = number_format((float) $movementAmount, 2);
            throw new \RuntimeException(
                "Insufficient balance. Available: ₦{$available}, " .
                    "Required: ₦{$needed} (₦{$movement} + ₦{$charge} service charge)"
            );
        }

        // Create execution record
        $execution = RuleExecution::create([
            'rule_id'            => $rule->id,
            'user_id'            => $user->id,
            'triggered_by'       => $triggeredBy,
            'rule_snapshot'      => $rule->snapshot(),
            'total_amount_ngn'   => $movementAmount,
            'service_charge_ngn' => $execFeeNgn,
            'total_debit_ngn'    => $totalDebit,
            'status'             => 'running',
            'started_at'         => now(),
        ]);

        // Single debit — movement + service charge leaves together
        $this->ledger->recordDebit(
            execution: $execution,
            user: $user,
            amount: $totalDebit,
            description: "Atlas rule \"{$rule->name}\"",
            serviceCharge: $execFeeNgn
        );
        $account->decrement('balance', (float) $totalDebit);

        // Record Atlas service charge revenue (internal only)
        if ((float) $execFeeNgn > 0) {
            FeeLedger::create([
                'user_id'            => $user->id,
                'execution_id'       => $execution->id,
                'execution_step_id'  => null,
                'fee_type'           => 'service_charge',
                'transaction_amount' => $movementAmount,
                'fee_amount'         => $execFeeNgn,
                'fee_rate'           => $execFeeNgn,
                'currency'           => 'NGN',
                'description'        => $execFeeData['description'],
                'meta'               => $execFeeData,
            ]);
        }

        // Execute each step with retry
        foreach ($actions as $action) {
            $stepAmountNgn = $action->resolveAmount($movementAmount);
            $config        = is_array($action->config)
                ? $action->config
                : (json_decode($action->config, true) ?? []);
            $config['user_id'] = $user->id;

            $step = ExecutionStep::create([
                'execution_id'   => $execution->id,
                'rule_action_id' => $action->id,
                'step_order'     => $action->step_order,
                'action_type'    => $action->action_type,
                'label'          => $action->label,
                'amount_ngn'     => $stepAmountNgn,
                'status'         => 'running',
                'started_at'     => now(),
            ]);

            try {
                $adapter = $this->resolveAdapter($action->action_type);

                // ── Retry wrapper — transient failures retry up to 3 times ────
                // Permanent failures (invalid account, fraud) throw immediately.
                $result = $this->retry->execute(
                    fn() => $adapter->execute($config, (float) $stepAmountNgn),
                    $action->label ?? $action->action_type
                );

                $step->update([
                    'status'           => 'completed',
                    'rail_reference'   => $result['rail_reference'],
                    'result'           => $result['result'],
                    'rollback_payload' => $result['rollback_payload'] ?? null,
                    'completed_at'     => now(),
                ]);

                // Ledger credit — crypto in token units, NGN in naira
                $isCrypto = $action->action_type === 'convert_crypto';
                if ($isCrypto) {
                    $tokenAmt = (string)($result['result']['amount_token'] ?? '0');
                    $token    = $result['result']['token']   ?? 'USDT';
                    $network  = $result['result']['network'] ?? 'trc20';

                    $this->ledger->recordCryptoCredit(
                        execution: $execution,
                        step: $step,
                        user: $user,
                        amountNgn: $stepAmountNgn,
                        amountToken: $tokenAmt,
                        token: $token,
                        network: $network,
                        description: $action->label ?? "Converted to {$token}",
                        reference: $result['rail_reference']
                    );

                    $this->recordCryptoRevenue($feeService, $execution, $step, $user, $stepAmountNgn, $config, $result);
                    $this->creditAtlasWallet($user->id, $network, $token, $tokenAmt);
                } else {
                    $this->ledger->recordStepCredit(
                        execution: $execution,
                        step: $step,
                        user: $user,
                        amount: $stepAmountNgn,
                        currency: 'NGN',
                        description: $action->label ?? $action->action_type,
                        reference: $result['rail_reference']
                    );
                }
            } catch (\Throwable $e) {
                $step->update([
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at'  => now(),
                ]);

                $execution->update([
                    'status'        => 'failed',
                    'error_message' => "Step {$action->step_order} failed: " . $e->getMessage(),
                    'completed_at'  => now(),
                ]);

                // Restore full debit on rollback
                $account->increment('balance', (float) $totalDebit);
                $this->rollback->rollback($execution->fresh());

                activity()
                    ->causedBy($user)
                    ->performedOn($execution)
                    ->withProperties(['step' => $action->step_order, 'error' => $e->getMessage()])
                    ->log('execution.failed');

                throw new \RuntimeException(
                    "Execution failed at step {$action->step_order} ({$action->label}): " . $e->getMessage()
                );
            }
        }

        $execution->update(['status' => 'completed', 'completed_at' => now()]);
        $rule->increment('execution_count');
        $rule->update(['last_triggered_at' => now()]);

        try {
            (new ReceiptService())->generate($execution->fresh(['steps', 'user', 'rule']));
        } catch (\Throwable $e) {
            Log::warning("Receipt generation failed: " . $e->getMessage());
        }

        activity()
            ->causedBy($user)
            ->performedOn($execution)
            ->withProperties([
                'movement_ngn'   => $movementAmount,
                'service_charge' => $execFeeNgn,
                'total_debit'    => $totalDebit,
                'steps'          => $actions->count(),
            ])
            ->log('execution.completed');

        return $execution->fresh(['steps']);
    }

    private function recordCryptoRevenue(FeeService $feeService, RuleExecution $execution, ExecutionStep $step, mixed $user, string $stepAmountNgn, array $config, array $result): void
    {
        try {
            $cryptoFee = $feeService->cryptoConversionFee((float) $stepAmountNgn, $config['token'] ?? 'USDT');
            if ($cryptoFee['fee_amount_ngn'] > 0) {
                FeeLedger::create([
                    'user_id'            => $user->id,
                    'execution_id'       => $execution->id,
                    'execution_step_id'  => $step->id,
                    'fee_type'           => 'fx_margin',
                    'transaction_amount' => $stepAmountNgn,
                    'fee_amount'         => $cryptoFee['fee_amount_ngn'],
                    'fee_rate'           => $cryptoFee['fee_rate'],
                    'currency'           => 'NGN',
                    'description'        => $cryptoFee['description'],
                    'meta'               => array_merge($cryptoFee, [
                        'atlas_rate'   => $result['result']['atlas_rate']   ?? null,
                        'market_rate'  => $result['result']['market_rate']  ?? null,
                        'amount_token' => $result['result']['amount_token'] ?? null,
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("FX margin record failed: " . $e->getMessage());
        }
    }

    private function creditAtlasWallet(string $userId, string $network, string $token, string $amountToken): void
    {
        try {
            $wallet = AtlasWallet::firstOrNew(['user_id' => $userId, 'network' => $network, 'token' => $token]);
            if (!$wallet->address) $wallet->address = AtlasWallet::generateAddress($network);
            $wallet->balance          = bcadd((string)($wallet->balance         ?? '0'), $amountToken, 8);
            $wallet->total_deposited  = bcadd((string)($wallet->total_deposited ?? '0'), $amountToken, 8);
            $wallet->last_activity_at = now();
            $wallet->save();
        } catch (\Throwable $e) {
            Log::warning("Atlas wallet credit failed: " . $e->getMessage());
        }
    }

    private function resolveMovementAmount(Rule $rule, ConnectedAccount $account): string
    {
        return match ($rule->total_amount_type) {
            'fixed'        => (string) $rule->total_amount,
            'full_balance' => (string) $account->balance,
            'percentage'   => bcdiv(bcmul((string)$account->balance, (string)$rule->total_amount, 10), '100', 6),
            default        => throw new \RuntimeException('Unknown amount type: ' . $rule->total_amount_type),
        };
    }

    private function resolveAdapter(string $actionType): mixed
    {
        return match ($actionType) {
            'send_bank'      => new FiatRail($this->encryption),
            'save_piggyvest' => new PiggyVestRail(),
            'save_cowrywise' => new CowrywiseRail(),
            'convert_crypto' => new CryptoRail(),
            'pay_bill'       => new BillRail(),
            default          => throw new \RuntimeException("No adapter for: {$actionType}"),
        };
    }
}

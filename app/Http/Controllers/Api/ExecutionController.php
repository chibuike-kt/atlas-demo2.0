<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Services\Engine\ExecutionEngine;
use App\Services\Security\IdempotencyService;
use App\Services\Security\VelocityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExecutionController extends Controller
{
    public function __construct(
        private readonly ExecutionEngine    $engine,
        private readonly IdempotencyService $idempotency,
        private readonly VelocityService    $velocity,
    ) {}

    /**
     * Execute a rule.
     *
     * Security layers applied in order:
     *   1. Ownership check    — rule belongs to authenticated user
     *   2. Active check       — rule must be enabled
     *   3. Idempotency check  — return existing result if this key was already processed
     *   4. Execution lock     — prevent parallel execution of same rule
     *   5. Velocity check     — fraud rate limiting
     *   6. Execute            — engine runs with retry logic
     *   7. Record idempotency result for future retries
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        $user = auth()->user();

        // ── 1. Ownership ──────────────────────────────────────────────────────
        $rule = Rule::where('user_id', $user->id)->findOrFail($id);

        // ── 2. Active check ───────────────────────────────────────────────────
        if (!$rule->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This rule is disabled. Enable it before executing.',
            ], 422);
        }

        // ── 3. Idempotency ────────────────────────────────────────────────────
        // Client sends X-Idempotency-Key header (UUID). If absent, we generate
        // a key from user+rule+current-minute (safe for manual triggers).
        $idempotencyKey = $request->header('X-Idempotency-Key')
            ?? hash('sha256', "{$user->id}:{$rule->id}:" . date('YmdHi'));

        $existingExecutionId = $this->idempotency->getExistingResult(
            $user->id,
            $rule->id,
            $idempotencyKey
        );

        if ($existingExecutionId) {
            $existing = RuleExecution::with('steps')->find($existingExecutionId);
            if ($existing) {
                return response()->json([
                    'success'     => true,
                    'idempotent'  => true, // tells client this is a cached response
                    'message'     => 'Execution already completed for this request.',
                    'data'        => $this->formatExecution($existing),
                ]);
            }
        }

        // ── 4. Execution lock (prevents parallel runs) ─────────────────────────
        try {
            $lock = $this->idempotency->acquireLock($user->id, $rule->id, $idempotencyKey);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 429);
        }

        try {
            // ── 5. Velocity / fraud checks ────────────────────────────────────
            $rule->load('connectedAccount');
            $movementAmount = $this->resolveMovementAmount($rule);

            try {
                $this->velocity->check($user, $rule->id, (float) $movementAmount);
            } catch (\RuntimeException $e) {
                Log::warning("Velocity check blocked execution", [
                    'user_id' => $user->id,
                    'rule_id' => $rule->id,
                    'amount'  => $movementAmount,
                    'reason'  => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code'    => 'VELOCITY_LIMIT',
                ], $e->getCode() ?: 429);
            }

            // ── 6. Execute ────────────────────────────────────────────────────
            $execution = $this->engine->execute($rule, 'manual');

            // ── 7. Record idempotency result ──────────────────────────────────
            $this->idempotency->recordResult($user->id, $rule->id, $idempotencyKey, $execution->id);

            // Invalidate velocity average cache so next anomaly check is fresh
            $this->velocity->invalidateAverageCache($user->id);

            return response()->json([
                'success' => true,
                'data'    => $this->formatExecution($execution),
            ]);
        } catch (\RuntimeException $e) {
            Log::error("Execution failed", [
                'user_id' => $user->id,
                'rule_id' => $rule->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } finally {
            // Always release lock — even on exception
            $lock?->release();
        }
    }

    public function index(Request $request): JsonResponse
    {
        $executions = RuleExecution::where('user_id', auth()->id())
            ->with(['steps', 'rule' => fn($q) => $q->withTrashed()])
            ->orderBy('started_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $executions->through(fn($e) => $this->formatExecution($e)),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $execution = RuleExecution::where('user_id', auth()->id())
            ->with(['steps', 'rule' => fn($q) => $q->withTrashed()])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatExecution($execution),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $ledgerService = app(\App\Services\LedgerService::class);
        return response()->json([
            'success' => true,
            'data'    => $ledgerService->history(auth()->id()),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveMovementAmount(Rule $rule): string
    {
        $account = $rule->connectedAccount;
        return match ($rule->total_amount_type) {
            'fixed'        => (string) $rule->total_amount,
            'full_balance' => (string) $account->balance,
            'percentage'   => bcdiv(bcmul((string)$account->balance, (string)$rule->total_amount, 10), '100', 6),
            default        => '0',
        };
    }

    private function formatExecution(RuleExecution $e): array
    {
        return [
            'id'                 => $e->id,
            'rule_name'          => $e->rule?->name ?? $e->rule_snapshot['name'] ?? 'Unknown',
            'status'             => $e->status,
            'triggered_by'       => $e->triggered_by,
            'total_amount'       => '₦' . number_format((float) $e->total_amount_ngn, 2),
            'service_charge'     => '₦' . number_format((float) $e->service_charge_ngn, 2),
            'total_debited'      => '₦' . number_format((float) $e->total_debit_ngn, 2),
            'steps_count'        => $e->steps->count(),
            'executed_at'        => $e->started_at,
            'completed_at'       => $e->completed_at,
            'error_message'      => $e->error_message,
            'steps'              => $e->steps->map(fn($s) => $this->formatStep($s)),
        ];
    }

    private function formatStep(\App\Models\ExecutionStep $s): array
    {
        $result  = is_array($s->result) ? $s->result : (json_decode($s->result, true) ?? []);
        $isCrypto = $s->action_type === 'convert_crypto';

        return [
            'step'           => $s->step_order,
            'label'          => $s->label,
            'action_type'    => $s->action_type,
            'amount'         => '₦' . number_format((float)$s->amount_ngn, 2),
            'amount_token'   => $isCrypto ? ($result['amount_token'] ?? null) : null,
            'token'          => $isCrypto ? ($result['token'] ?? null) : null,
            'network'        => $isCrypto ? ($result['network'] ?? null) : null,
            'status'         => $s->status,
            'rail_reference' => $s->rail_reference,
            'error_message'  => $s->error_message,
            'completed_at'   => $s->completed_at,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Engine\ExecutionEngine;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionController extends Controller
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly LedgerService   $ledger,
    ) {
        auth()->shouldUse('api');
    }

    /**
     * POST /api/rules/{id}/execute
     * Manually trigger a rule execution.
     */
    public function execute(string $ruleId): JsonResponse
    {
        $rule = auth()->user()
            ->rules()
            ->with(['actions', 'connectedAccount', 'user'])
            ->findOrFail($ruleId);

        if (!$rule->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Rule is inactive. Activate it before executing.',
            ], 400);
        }

        try {
            $execution = $this->engine->execute($rule, 'manual');

            return response()->json([
                'success' => true,
                'message' => 'Rule executed successfully.',
                'data'    => $this->formatExecution($execution),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/executions
     * List all executions for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $executions = auth()->user()
            ->ruleExecutions()
            ->with(['steps', 'rule'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn($e) => $this->formatExecution($e));

        return response()->json([
            'success' => true,
            'data'    => $executions,
        ]);
    }

    /**
     * GET /api/executions/{id}
     * Single execution detail with all steps.
     */
    public function show(string $id): JsonResponse
    {
        $execution = auth()->user()
            ->ruleExecutions()
            ->with(['steps', 'rule'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatExecution($execution),
        ]);
    }

    /**
     * GET /api/ledger
     */
    public function ledger(Request $request): JsonResponse
    {
        $currency = $request->get('currency', 'NGN');
        $entries  = $this->ledger->history(auth()->id(), $currency);

        return response()->json([
            'success' => true,
            'data'    => $entries,
        ]);
    }

    private function formatExecution($execution): array
    {
        return [
            'id'           => $execution->id,
            'rule'         => [
                'id'   => $execution->rule?->id,
                'name' => $execution->rule?->name,
            ],
            'triggered_by'   => $execution->triggered_by,
            'total_amount'   => '₦' . number_format((float)$execution->total_amount_ngn, 2),
            'status'         => $execution->status,
            'started_at'     => $execution->started_at,
            'completed_at'   => $execution->completed_at,
            'error_message'  => $execution->error_message,
            'steps'          => $execution->steps->map(fn($s) => [
                'step'          => $s->step_order,
                'label'         => $s->label,
                'action_type'   => $s->action_type,
                'amount'        => '₦' . number_format((float)$s->amount_ngn, 2),
                'status'        => $s->status,
                'rail_reference' => $s->rail_reference,
                'result'        => $s->result,
                'completed_at'  => $s->completed_at,
            ])->toArray(),
        ];
    }
}

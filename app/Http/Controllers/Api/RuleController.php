<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuleController extends Controller
{
    public function __construct(private readonly RuleService $ruleService)
    {
        auth()->shouldUse('api');
    }

    /**
     * GET /api/rules
     */
    public function index(): JsonResponse
    {
        $rules = auth()->user()
            ->rules()
            ->with(['actions', 'connectedAccount'])
            ->get()
            ->map(fn($r) => $this->ruleService->format($r));

        return response()->json([
            'success' => true,
            'data'    => $rules,
        ]);
    }

    /**
     * POST /api/rules
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'                  => ['required', 'string', 'max:150'],
            'description'           => ['nullable', 'string'],
            'connected_account_id'  => ['required', 'uuid', 'exists:connected_accounts,id'],
            'trigger_type'          => ['required', 'in:schedule,deposit,balance,manual'],
            'trigger_config'        => ['required', 'array'],
            'total_amount_type'     => ['required', 'in:fixed,percentage,full_balance'],
            'total_amount'          => ['required_unless:total_amount_type,full_balance', 'numeric', 'min:1'],
            'actions'               => ['required', 'array', 'min:1'],
            'actions.*.action_type' => ['required', 'in:send_bank,save_piggyvest,save_cowrywise,convert_crypto,pay_bill'],
            'actions.*.amount_type' => ['required', 'in:fixed,percentage'],
            'actions.*.amount'      => ['required', 'numeric', 'min:0.01'],
            'actions.*.config'      => ['required', 'array'],
            'actions.*.label'       => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $rule = $this->ruleService->create(
                auth()->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => "Rule \"{$rule->name}\" created.",
                'data'    => $this->ruleService->format($rule),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/rules/{id}
     */
    public function show(string $id): JsonResponse
    {
        $rule = auth()->user()
            ->rules()
            ->with(['actions', 'connectedAccount'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->ruleService->format($rule),
        ]);
    }

    /**
     * PUT /api/rules/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name'                  => ['sometimes', 'string', 'max:150'],
            'description'           => ['nullable', 'string'],
            'connected_account_id'  => ['sometimes', 'uuid', 'exists:connected_accounts,id'],
            'trigger_type'          => ['sometimes', 'in:schedule,deposit,balance,manual'],
            'trigger_config'        => ['sometimes', 'array'],
            'total_amount_type'     => ['sometimes', 'in:fixed,percentage,full_balance'],
            'total_amount'          => ['nullable', 'numeric', 'min:1'],
            'actions'               => ['sometimes', 'array', 'min:1'],
            'actions.*.action_type' => ['required_with:actions', 'in:send_bank,save_piggyvest,save_cowrywise,convert_crypto,pay_bill'],
            'actions.*.amount_type' => ['required_with:actions', 'in:fixed,percentage'],
            'actions.*.amount'      => ['required_with:actions', 'numeric', 'min:0.01'],
            'actions.*.config'      => ['required_with:actions', 'array'],
            'actions.*.label'       => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $rule = $this->ruleService->update(
                auth()->user(),
                $id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Rule updated.',
                'data'    => $this->ruleService->format($rule),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * DELETE /api/rules/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->ruleService->delete(auth()->user(), $id);

            return response()->json([
                'success' => true,
                'message' => 'Rule deleted.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/rules/{id}/toggle
     * Activate or deactivate a rule.
     */
    public function toggle(string $id): JsonResponse
    {
        $rule = $this->ruleService->toggle(auth()->user(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Rule ' . ($rule->is_active ? 'activated' : 'deactivated') . '.',
            'data'    => $this->ruleService->format($rule),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\RuleExecution;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct()
    {
        auth()->shouldUse('api');
    }

    public function index(): JsonResponse
    {
        $user = auth()->user();

        // Connected accounts
        $accounts = $user->connectedAccounts()
            ->where('is_active', true)
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'institution' => $a->institution_name,
                'type'        => $a->account_type,
                'balance'     => (float) $a->balance,
                'formatted'   => $a->formattedBalance(),
                'is_primary'  => $a->is_primary,
                'synced_at'   => $a->balance_synced_at,
            ]);

        $totalBalance = $accounts->sum('balance');

        // Rules summary
        $rules       = $user->rules();
        $activeRules = (clone $rules)->where('is_active', true)->count();
        $totalRules  = $rules->count();

        // Recent executions
        $recentExecutions = $user->ruleExecutions()
            ->with(['steps', 'rule'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($e) => [
                'id'           => $e->id,
                'rule_name'    => $e->rule?->name,
                'total_amount' => '₦' . number_format((float) $e->total_amount_ngn, 2),
                'status'       => $e->status,
                'steps_count'  => $e->steps->count(),
                'triggered_by' => $e->triggered_by,
                'executed_at'  => $e->created_at,
            ]);

        // Execution stats
        $totalExecutions     = $user->ruleExecutions()->count();
        $successfulExecutions = $user->ruleExecutions()->where('status', 'completed')->count();
        $totalMoved          = $user->ruleExecutions()
            ->where('status', 'completed')
            ->sum('total_amount_ngn');

        // Recent ledger entries
        $ledger = LedgerEntry::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($e) => [
                'type'        => $e->entry_type,
                'amount'      => $e->formattedAmount(),
                'currency'    => $e->currency,
                'description' => $e->description,
                'reference'   => $e->reference,
                'date'        => $e->created_at,
            ]);

        // Saved contacts count
        $contactsCount = $user->savedContacts()->where('is_active', true)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => [
                    'name'  => $user->full_name,
                    'email' => $user->email,
                ],
                'summary' => [
                    'total_balance'        => '₦' . number_format($totalBalance, 2),
                    'total_balance_raw'    => $totalBalance,
                    'active_rules'         => $activeRules,
                    'total_rules'          => $totalRules,
                    'total_executions'     => $totalExecutions,
                    'successful_executions' => $successfulExecutions,
                    'total_moved'          => '₦' . number_format((float) $totalMoved, 2),
                    'contacts_count'       => $contactsCount,
                ],
                'accounts'          => $accounts,
                'recent_executions' => $recentExecutions,
                'ledger'            => $ledger,
            ],
        ]);
    }
}

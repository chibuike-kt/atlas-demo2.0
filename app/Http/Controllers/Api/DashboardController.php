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

        // ── Accounts ─────────────────────────────────────────────────────────
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
                'account_number' => $a->masked_account_number ?? '**********',
                'synced_at'   => $a->balance_synced_at,
            ]);

        $totalBalance = $accounts->sum('balance');

        // ── Rules — include soft-deleted in total count ───────────────────────
        $activeRules = $user->rules()->where('is_active', true)->count();
        $totalRules  = $user->rules()->count(); // excludes soft-deleted via SoftDeletes scope
        $allTimeRules = $user->rules()->withTrashed()->count(); // includes deleted

        // ── Executions — never deleted, always accurate ───────────────────────
        $totalExecutions      = RuleExecution::where('user_id', $user->id)->count();
        $successfulExecutions = RuleExecution::where('user_id', $user->id)
            ->where('status', 'completed')
            ->count();
        $failedExecutions     = RuleExecution::where('user_id', $user->id)
            ->where('status', 'failed')
            ->count();
        $totalMoved           = RuleExecution::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount_ngn');

        // ── Recent executions — withTrashed on rule so deleted rules still show ─
        $recentExecutions = RuleExecution::where('user_id', $user->id)
            ->with(['steps', 'rule' => fn($q) => $q->withTrashed()])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($e) => [
                'id'           => $e->id,
                'rule_name'    => $e->rule?->name ?? '[Deleted Rule]',
                'rule_deleted' => $e->rule?->trashed() ?? false,
                'total_amount' => '₦' . number_format((float) $e->total_amount_ngn, 2),
                'status'       => $e->status,
                'steps_count'  => $e->steps->count(),
                'triggered_by' => $e->triggered_by,
                'executed_at'  => $e->created_at,
            ]);

        // ── Ledger — permanent, never deleted ────────────────────────────────
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

        // ── Contacts ──────────────────────────────────────────────────────────
        $contactsCount = $user->savedContacts()->where('is_active', true)->count();
        $walletsCount  = $user->savedContacts()
            ->where('is_active', true)
            ->where('type', 'crypto')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => [
                    'name'  => $user->full_name,
                    'email' => $user->email,
                ],
                'summary' => [
                    'total_balance'         => '₦' . number_format($totalBalance, 2),
                    'total_balance_raw'     => $totalBalance,
                    'active_rules'          => $activeRules,
                    'total_rules'           => $totalRules,
                    'all_time_rules'        => $allTimeRules,
                    'total_executions'      => $totalExecutions,
                    'successful_executions' => $successfulExecutions,
                    'failed_executions'     => $failedExecutions,
                    'total_moved'           => '₦' . number_format((float) $totalMoved, 2),
                    'contacts_count'        => $contactsCount,
                    'wallets_count'         => $walletsCount,
                ],
                'accounts'          => $accounts,
                'recent_executions' => $recentExecutions,
                'ledger'            => $ledger,
            ],
        ]);
    }
}

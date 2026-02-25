<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mono\MonoService;
use App\Services\EncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly MonoService $monoService,
        private readonly EncryptionService $encryption,
    ) {
        auth()->shouldUse('api');
    }

    /**
     * POST /api/accounts/connect
     * Exchange Mono Connect code for linked bank account.
     */
    public function connect(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $account = $this->monoService->connectAccount(
                $request->code,
                auth()->user()
            );

            activity()
                ->causedBy(auth()->user())
                ->performedOn($account)
                ->log('account.connected');

            return response()->json([
                'success' => true,
                'message' => 'Bank account connected successfully.',
                'data'    => $this->formatAccount($account),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/accounts
     * List all connected accounts for authenticated user.
     */
    public function index(): JsonResponse
    {
        $accounts = auth()->user()
            ->connectedAccounts()
            ->where('is_active', true)
            ->get()
            ->map(fn($account) => $this->formatAccount($account));

        return response()->json([
            'success' => true,
            'data'    => $accounts,
        ]);
    }

    /**
     * POST /api/accounts/{id}/sync
     * Sync latest balance from Mono.
     */
    public function sync(string $id): JsonResponse
    {
        $account = auth()->user()
            ->connectedAccounts()
            ->findOrFail($id);

        try {
            $account = $this->monoService->syncBalance($account);

            return response()->json([
                'success' => true,
                'message' => 'Balance synced.',
                'data'    => $this->formatAccount($account),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/accounts/{id}/transactions
     */
    public function transactions(string $id): JsonResponse
    {
        $account = auth()->user()
            ->connectedAccounts()
            ->findOrFail($id);

        $transactions = $this->monoService->getTransactions($account);

        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ]);
    }

    private function formatAccount($account): array
    {
        return [
            'id'               => $account->id,
            'institution'      => $account->institution_name,
            'institution_code' => $account->institution_code,
            'account_name'     => $account->account_name,
            'account_number'   => $this->maskAccountNumber($account),
            'account_type'     => $account->account_type,
            'currency'         => $account->currency,
            'balance'          => (float) $account->balance,
            'balance_formatted' => $account->formattedBalance(),
            'balance_synced_at' => $account->balance_synced_at,
            'is_primary'       => $account->is_primary,
        ];
    }

    private function maskAccountNumber($account): string
    {
        try {
            $number = $this->encryption->decrypt($account->account_number_enc);
            return '******' . substr($number, -4);
        } catch (\Throwable) {
            return '**********';
        }
    }
}

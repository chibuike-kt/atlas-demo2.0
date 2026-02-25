<?php

namespace App\Services\Mono;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MonoService
{
  private bool $simulate;
  private string $secretKey;
  private string $baseUrl;
  private MonoSimulator $simulator;
  private EncryptionService $encryption;

  public function __construct(EncryptionService $encryption)
  {
    $this->simulate   = (bool) config('rails.mono.simulate', true);
    $this->secretKey  = (string) config('rails.mono.secret_key', '');
    $this->baseUrl    = (string) config('rails.mono.base_url', 'https://api.withmono.com');
    $this->simulator  = new MonoSimulator();
    $this->encryption = $encryption;
  }

  /**
   * Exchange Mono Connect code for account details and store.
   * This is called after user completes the Mono Connect widget flow.
   */
  public function connectAccount(string $code, User $user): ConnectedAccount
  {
    $data = $this->simulate
      ? $this->simulator->exchangeCode($code, $user)
      : $this->exchangeCodeReal($code);

    $account = $data['account'];

    // Encrypt sensitive data before storing
    $accountNumberEnc = $this->encryption->encrypt(
      (string) $account['accountNumber']
    );

    // Set as primary if user has no accounts yet
    $isPrimary = $user->connectedAccounts()->count() === 0;

    return ConnectedAccount::create([
      'user_id'            => $user->id,
      'mono_account_id'    => $account['id'],
      'institution_name'   => $account['institution']['name'],
      'institution_code'   => $account['institution']['bankCode'],
      'account_name'       => $account['name'],
      'account_number_enc' => $accountNumberEnc,
      'account_type'       => $account['type'],
      'currency'           => $account['currency'] ?? 'NGN',
      'balance'            => $account['balance'] / 100, // kobo to naira
      'balance_synced_at'  => now(),
      'is_primary'         => $isPrimary,
      'meta'               => [
        'mono_id' => $data['id'] ?? null,
      ],
    ]);
  }

  /**
   * Sync latest balance from Mono.
   */
  public function syncBalance(ConnectedAccount $account): ConnectedAccount
  {
    $data = $this->simulate
      ? $this->simulator->getAccount($account->mono_account_id)
      : $this->getAccountReal($account->mono_account_id);

    $balanceNaira = $data['data']['account']['balance'] / 100;

    $account->update([
      'balance'           => $balanceNaira,
      'balance_synced_at' => now(),
    ]);

    return $account->fresh();
  }

  /**
   * Get transaction history.
   */
  public function getTransactions(ConnectedAccount $account): array
  {
    $data = $this->simulate
      ? $this->simulator->getTransactions($account->mono_account_id)
      : $this->getTransactionsReal($account->mono_account_id);

    return array_map(function ($txn) {
      return [
        'id'          => $txn['id'],
        'amount'      => $txn['amount'] / 100,
        'date'        => $txn['date'],
        'narration'   => $txn['narration'],
        'type'        => $txn['type'],
        'balance'     => $txn['balance'] / 100,
      ];
    }, $data['data']);
  }

  // ── Real Mono API calls (used when MONO_SIMULATE=false) ──────────────────

  private function exchangeCodeReal(string $code): array
  {
    $response = Http::withHeaders([
      'mono-sec-key' => $this->secretKey,
      'Content-Type' => 'application/json',
    ])->post("{$this->baseUrl}/v2/accounts/auth", [
      'code' => $code,
    ]);

    if (!$response->successful()) {
      throw new \RuntimeException('Mono account linking failed: ' . $response->body());
    }

    return $response->json();
  }

  private function getAccountReal(string $monoAccountId): array
  {
    $response = Http::withHeaders([
      'mono-sec-key' => $this->secretKey,
    ])->get("{$this->baseUrl}/v2/accounts/{$monoAccountId}");

    if (!$response->successful()) {
      throw new \RuntimeException('Mono balance sync failed.');
    }

    return $response->json();
  }

  private function getTransactionsReal(string $monoAccountId): array
  {
    $response = Http::withHeaders([
      'mono-sec-key' => $this->secretKey,
    ])->get("{$this->baseUrl}/v2/accounts/{$monoAccountId}/transactions");

    if (!$response->successful()) {
      throw new \RuntimeException('Mono transactions fetch failed.');
    }

    return $response->json();
  }
}

<?php

namespace App\Services\Mono;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * MonoSimulator
 *
 * Mirrors the exact Mono API response shape.
 * When real Mono keys are available, MonoService switches
 * to real HTTP calls — this class is never touched.
 */
class MonoSimulator
{
  private array $institutions = [
    [
      'name' => 'Guaranty Trust Bank',
      'code' => '058',
      'type' => 'CURRENT',
    ],
    [
      'name' => 'Access Bank',
      'code' => '044',
      'type' => 'SAVINGS',
    ],
    [
      'name' => 'Zenith Bank',
      'code' => '057',
      'type' => 'CURRENT',
    ],
    [
      'name' => 'First Bank of Nigeria',
      'code' => '011',
      'type' => 'SAVINGS',
    ],
    [
      'name' => 'United Bank for Africa',
      'code' => '033',
      'type' => 'CURRENT',
    ],
  ];

  /**
   * Simulate Mono Connect — returns fake account linked response.
   * In production: Mono widget returns a code, we exchange it for account ID.
   */
  public function exchangeCode(string $code, User $user): array
  {
    // Pick institution based on code for deterministic demo
    $institution = $this->institutions[array_search(
      substr($code, 0, 1),
      ['g', 'a', 'z', 'f', 'u']
    ) ?: 0];

    return [
      'id'          => 'mono_' . Str::random(24),
      'account'     => [
        'id'          => 'acc_' . Str::random(20),
        'name'        => $user->full_name,
        'accountNumber' => $this->generateAccountNumber($institution['code']),
        'type'        => $institution['type'],
        'balance'     => $this->generateBalance(),
        'currency'    => 'NGN',
        'institution' => [
          'name'    => $institution['name'],
          'bankCode' => $institution['code'],
          'type'    => 'PERSONAL_BANKING',
        ],
      ],
    ];
  }

  /**
   * Simulate balance sync — Mono GET /accounts/:id
   */
  public function getAccount(string $monoAccountId): array
  {
    return [
      'meta' => ['data_status' => 'AVAILABLE'],
      'data' => [
        'account' => [
          'id'      => $monoAccountId,
          'balance' => $this->generateBalance(),
          'currency' => 'NGN',
        ],
      ],
    ];
  }

  /**
   * Simulate transaction history — Mono GET /accounts/:id/transactions
   */
  public function getTransactions(string $monoAccountId): array
  {
    $transactions = [];
    $descriptions = [
      'SALARY PAYMENT - EMPLOYER LTD',
      'POS PURCHASE - SHOPRITE IKEJA',
      'TRANSFER FROM JOHN DOE',
      'ATM WITHDRAWAL - GTB VICTORIA ISLAND',
      'AIRTIME PURCHASE - MTN',
      'TRANSFER TO SAVINGS',
      'UTILITY BILL - EKEDC',
      'DSTV SUBSCRIPTION',
    ];

    for ($i = 0; $i < 8; $i++) {
      $isCredit = $i === 0 || $i === 2;
      $transactions[] = [
        'id'      => 'txn_' . Str::random(16),
        'amount'  => rand(5000, 500000) * 100, // Mono returns kobo
        'date'    => now()->subDays($i * 3)->toISOString(),
        'narration' => $descriptions[$i],
        'type'    => $isCredit ? 'credit' : 'debit',
        'balance' => rand(1000000, 5000000) * 100,
      ];
    }

    return ['data' => $transactions];
  }

  private function generateAccountNumber(string $bankCode): string
  {
    // Nigerian NUBAN format: bank code influences first digits
    return $bankCode[0] . rand(100000000, 999999999);
  }

  private function generateBalance(): int
  {
    // Returns in kobo (Mono standard) — ₦2.45M
    return 245000000;
  }
}

<?php

namespace App\Services;

/**
 * FeeService
 *
 * Atlas transaction fee engine.
 *
 * Fee model:
 *   Execution fee (all steps):  1–5 steps = ₦20, 6–10 = ₦30, 10+ = ₦50
 *   Crypto conversion:          FX spread + $0.05 flat per conversion
 *   Crypto withdrawal:          $0.50 flat per external withdrawal
 *   Bill payments:              ₦0 to user (Atlas earns biller commission)
 *   PiggyVest / Cowrywise:      ₦0 (pending partnership)
 *
 * Rates are loaded from system_settings table with 1-hour cache.
 * Falls back to hardcoded defaults if DB unavailable.
 */
class FeeService
{
  // ── Execution fee tiers — based on total steps fired ─────────────────────
  const EXECUTION_FEE_TIERS = [
    ['min' => 1,  'max' => 5,           'fee' => 20.00],
    ['min' => 6,  'max' => 10,          'fee' => 30.00],
    ['min' => 11, 'max' => PHP_INT_MAX, 'fee' => 50.00],
  ];

  // ── Crypto fees ───────────────────────────────────────────────────────────
  const CRYPTO_CONVERT_FEE_USD  = 0.05;  // $0.05 flat per conversion
  const CRYPTO_WITHDRAW_FEE_USD = 0.50;  // $0.50 flat per withdrawal

  // ── Default rates (fallback if DB/cache unavailable) ─────────────────────
  const DEFAULT_USD_NGN_RATE   = 1650.00;
  const DEFAULT_MARKET_RATES   = [
    'USDT' => 1600.00,
    'BTC'  => 96000000.00,
    'ETH'  => 5100000.00,
    'BNB'  => 840000.00,
    'SOL'  => 170000.00,
    'USDC' => 1600.00,
  ];
  const DEFAULT_ATLAS_RATES    = [
    'USDT' => 1650.00,
    'BTC'  => 98880000.00,
    'ETH'  => 5253000.00,
    'BNB'  => 865200.00,
    'SOL'  => 175100.00,
    'USDC' => 1650.00,
  ];

  // ── Dynamic rate loading ──────────────────────────────────────────────────
  // Reads from system_settings table, cached for 1 hour.
  // In production: update system_settings via admin panel or cron job
  // that pulls live rates from Binance/Bybit API.
  private function getRate(string $key, float $default): float
  {
    try {
      return (float) \Illuminate\Support\Facades\Cache::remember(
        "atlas_rate_{$key}",
        3600, // 1 hour cache
        function () use ($key, $default) {
          $setting = \Illuminate\Support\Facades\DB::table('system_settings')
            ->where('key', $key)
            ->value('value');
          return $setting ? (float) $setting : $default;
        }
      );
    } catch (\Throwable) {
      return $default; // Fallback if DB unavailable
    }
  }

  private function getUsdNgnRate(): float
  {
    return $this->getRate('usd_ngn_rate', self::DEFAULT_USD_NGN_RATE);
  }

  private function getMarketRate(string $token): float
  {
    $default = self::DEFAULT_MARKET_RATES[$token] ?? 1600.00;
    return $this->getRate("market_rate_{$token}", $default);
  }

  private function getAtlasRate(string $token): float
  {
    $default = self::DEFAULT_ATLAS_RATES[$token] ?? 1650.00;
    return $this->getRate("atlas_rate_{$token}", $default);
  }

  // ── Execution fee — one flat charge per execution based on total steps ────

  public function executionFee(int $totalStepCount): array
  {
    if ($totalStepCount === 0) {
      return $this->noFee('execution');
    }

    $fee = 20.00;
    foreach (self::EXECUTION_FEE_TIERS as $tier) {
      if ($totalStepCount >= $tier['min'] && $totalStepCount <= $tier['max']) {
        $fee = $tier['fee'];
        break;
      }
    }

    $tierLabel = match (true) {
      $totalStepCount >= 11 => '11+ steps',
      $totalStepCount >= 6  => '6–10 steps',
      default               => '1–5 steps',
    };

    return [
      'fee_type'       => 'execution',
      'fee_amount_ngn' => $fee,
      'fee_rate'       => $fee,
      'steps'          => $totalStepCount,
      'user_pays'      => '₦' . number_format($fee, 2),
      'description'    => "Execution fee ({$totalStepCount} step(s) — {$tierLabel})",
      'breakdown'      => "₦{$fee} flat for {$totalStepCount} step(s) in this execution",
    ];
  }

  // ── Crypto conversion fee — FX spread + $0.05 flat ───────────────────────

  public function cryptoConversionFee(float $amountNgn, string $token = 'USDT', string $direction = 'buy'): array
  {
    $marketRate = $this->getMarketRate($token);
    $atlasRate  = $this->getAtlasRate($token);
    $usdNgnRate = $this->getUsdNgnRate();

    // $0.05 flat in NGN at current rate
    $flatFeeNgn = round(self::CRYPTO_CONVERT_FEE_USD * $usdNgnRate, 2);

    // FX spread calculation
    $tokensAtMarket = round($amountNgn / $marketRate, 8);
    $tokensAtAtlas  = round($amountNgn / $atlasRate,  8);
    $spreadTokens   = round($tokensAtMarket - $tokensAtAtlas, 8);
    $spreadNgn      = round($spreadTokens * $marketRate, 2);

    if ($direction === 'buy') {
      // NGN → USDT: user gets fewer tokens at Atlas rate
      $userReceives = $tokensAtAtlas . ' ' . $token;
    } else {
      // USDT → NGN: user gets less naira at Atlas rate
      $userReceives = '₦' . number_format(max(0, $amountNgn - $spreadNgn - $flatFeeNgn), 2);
    }

    $totalFeeNgn = round($spreadNgn + $flatFeeNgn, 2);

    return [
      'fee_type'             => 'crypto_conversion',
      'fee_amount_ngn'       => $totalFeeNgn,
      'flat_fee_ngn'         => $flatFeeNgn,
      'flat_fee_usd'         => self::CRYPTO_CONVERT_FEE_USD,
      'spread_ngn'           => $spreadNgn,
      'fee_rate'             => self::CRYPTO_CONVERT_FEE_USD,
      'market_rate'          => $marketRate,
      'atlas_rate'           => $atlasRate,
      'usd_ngn_rate'         => $usdNgnRate,
      'direction'            => $direction,
      'token'                => $token,
      'user_receives'        => $userReceives,
      'user_pays'            => '$0.05 + FX spread',
      'description'          => "Crypto conversion fee ({$direction})",
      'breakdown'            => "\$0.05 flat (₦{$flatFeeNgn}) + spread ₦{$spreadNgn} = ₦{$totalFeeNgn}",
    ];
  }

  // ── Crypto withdrawal — $0.50 flat ────────────────────────────────────────

  public function cryptoWithdrawalFee(): array
  {
    $usdNgnRate = $this->getUsdNgnRate();
    $feeNgn     = round(self::CRYPTO_WITHDRAW_FEE_USD * $usdNgnRate, 2);

    return [
      'fee_type'       => 'crypto_withdrawal',
      'fee_amount_ngn' => $feeNgn,
      'fee_usd'        => self::CRYPTO_WITHDRAW_FEE_USD,
      'fee_rate'       => self::CRYPTO_WITHDRAW_FEE_USD,
      'usd_ngn_rate'   => $usdNgnRate,
      'user_pays'      => '$0.50 (₦' . number_format($feeNgn) . ')',
      'description'    => 'Crypto withdrawal fee',
      'breakdown'      => '$0.50 × ₦' . number_format($usdNgnRate) . '/$ = ₦' . number_format($feeNgn),
    ];
  }

  // ── Calculate all fees for an execution upfront — for rule builder preview

  public function calculateForExecution(array $actions): array
  {
    $totalSteps  = count($actions);
    $breakdown   = [];
    $totalFeeNgn = 0;

    // One execution fee for all steps combined
    $execFee = $this->executionFee($totalSteps);
    if ($execFee['fee_amount_ngn'] > 0) {
      $breakdown[]  = $execFee;
      $totalFeeNgn += $execFee['fee_amount_ngn'];
    }

    // Per-conversion crypto fee (separate from execution fee)
    foreach ($actions as $action) {
      if (($action['action_type'] ?? '') === 'convert_crypto') {
        $token       = $action['config']['token'] ?? 'USDT';
        $amt         = (float) ($action['amount'] ?? 0);
        $fee         = $this->cryptoConversionFee($amt, $token);
        $breakdown[] = $fee;
        $totalFeeNgn += $fee['fee_amount_ngn'];
      }
    }

    return [
      'total_fee_ngn' => $totalFeeNgn,
      'formatted'     => '₦' . number_format($totalFeeNgn, 2),
      'breakdown'     => $breakdown,
      'note'          => $this->executionFeeNote($totalSteps),
    ];
  }

  // ── Execution fee description ─────────────────────────────────────────────

  public function executionFeeDescription(float $fee, int $steps): string
  {
    return match ((int) $fee) {
      50    => "Execution fee — {$steps} steps (₦50 flat, 11+ steps)",
      30    => "Execution fee — {$steps} steps (₦30 flat, 6–10 steps)",
      default => "Execution fee — {$steps} steps (₦20 flat, 1–5 steps)",
    };
  }

  private function executionFeeNote(int $totalSteps): string
  {
    if ($totalSteps === 0) return '';
    return match (true) {
      $totalSteps >= 11 => "₦50 flat — 11+ steps in this execution",
      $totalSteps >= 6  => "₦30 flat — 6–10 steps in this execution",
      default           => "₦20 flat — 1–5 steps in this execution",
    };
  }

  // ── Revenue reporting ─────────────────────────────────────────────────────

  public function totalRevenue(): array
  {
    $ledger = \App\Models\FeeLedger::query();
    return [
      'all_time'   => '₦' . number_format((float) (clone $ledger)->sum('fee_amount'), 2),
      'this_month' => '₦' . number_format(
        (float) (clone $ledger)->whereMonth('created_at', now()->month)->sum('fee_amount'),
        2
      ),
      'by_type'    => (clone $ledger)
        ->selectRaw('fee_type, SUM(fee_amount) as total, COUNT(*) as count')
        ->groupBy('fee_type')
        ->get()
        ->mapWithKeys(fn($r) => [$r->fee_type => [
          'total' => '₦' . number_format((float) $r->total, 2),
          'count' => $r->count,
        ]])
        ->toArray(),
    ];
  }

  public function userSummary(string $userId): array
  {
    $ledger = \App\Models\FeeLedger::where('user_id', $userId);
    return [
      'total_paid' => '₦' . number_format((float) (clone $ledger)->sum('fee_amount'), 2),
      'this_month' => '₦' . number_format(
        (float) (clone $ledger)->whereMonth('created_at', now()->month)->sum('fee_amount'),
        2
      ),
      'by_type'    => (clone $ledger)
        ->selectRaw('fee_type, SUM(fee_amount) as total, COUNT(*) as count')
        ->groupBy('fee_type')
        ->get()
        ->mapWithKeys(fn($r) => [$r->fee_type => [
          'total' => '₦' . number_format((float) $r->total, 2),
          'count' => $r->count,
        ]])
        ->toArray(),
    ];
  }

  // ── No fee ────────────────────────────────────────────────────────────────

  private function noFee(string $type = 'none'): array
  {
    return [
      'fee_type'       => $type,
      'fee_amount_ngn' => 0,
      'fee_rate'       => 0,
      'steps'          => 0,
      'user_pays'      => '₦0',
      'description'    => 'No fee',
      'breakdown'      => '',
    ];
  }
}

<?php

namespace App\Services;

class FeeService
{
  // ── Rates ─────────────────────────────────────────────────────────────────
  const FIAT_STEP_FEE         = 20.00;   // ₦20 per send_bank step
  const FIAT_BULK_FEE         = 10.00;   // ₦10 per step when 5+ steps
  const FIAT_BULK_THRESHOLD   = 5;       // steps needed to trigger bulk rate
  const CRYPTO_CONVERT_FEE_USD = 0.05;  // $0.05 per conversion
  const CRYPTO_WITHDRAW_FEE_USD = 0.50; // $0.50 per external withdrawal
  const USD_NGN_RATE          = 1650.00; // Atlas rate (updated in production via live feed)

  // FX Spread rates
  const MARKET_RATES = [
    'USDT' => 1600.00,
    'BTC'  => 96000000.00,
    'ETH'  => 5100000.00,
    'BNB'  => 840000.00,
    'SOL'  => 170000.00,
    'USDC' => 1600.00,
  ];

  const ATLAS_RATES = [
    'USDT' => 1650.00,
    'BTC'  => 98880000.00,
    'ETH'  => 5253000.00,
    'BNB'  => 865200.00,
    'SOL'  => 175100.00,
    'USDC' => 1650.00,
  ];

  // ── Bank transfer fee ─────────────────────────────────────────────────────
  // Called once per execution with total send_bank step count
  public function bankTransferFee(int $sendBankStepCount): array
  {
    if ($sendBankStepCount === 0) {
      return $this->noFee('fiat_transfer');
    }

    $ratePerStep = $sendBankStepCount >= self::FIAT_BULK_THRESHOLD
      ? self::FIAT_BULK_FEE
      : self::FIAT_STEP_FEE;

    $totalFee  = $ratePerStep * $sendBankStepCount;
    $isBulk    = $sendBankStepCount >= self::FIAT_BULK_THRESHOLD;

    return [
      'fee_type'        => 'fiat_transfer',
      'fee_amount_ngn'  => $totalFee,
      'fee_rate'        => $ratePerStep,
      'steps'           => $sendBankStepCount,
      'bulk_discount'   => $isBulk,
      'user_pays'       => '₦' . number_format($totalFee, 2),
      'description'     => $isBulk
        ? "Transfer fee: {$sendBankStepCount} steps × ₦{$ratePerStep} (bulk discount applied)"
        : "Transfer fee: {$sendBankStepCount} step(s) × ₦{$ratePerStep}",
      'breakdown'       => $isBulk
        ? "5+ steps — ₦10/step instead of ₦20/step"
        : "₦20 per transfer",
    ];
  }

  // ── Crypto conversion fee ─────────────────────────────────────────────────
  // NGN → USDT or USDT → NGN
  public function cryptoConversionFee(float $amountNgn, string $token = 'USDT', string $direction = 'buy'): array
  {
    $marketRate = self::MARKET_RATES[$token] ?? 1600.00;
    $atlasRate  = self::ATLAS_RATES[$token]  ?? 1650.00;

    // $0.05 flat conversion fee in NGN
    $flatFeeNgn = round(self::CRYPTO_CONVERT_FEE_USD * self::USD_NGN_RATE, 2);

    if ($direction === 'buy') {
      // NGN → USDT: user gets tokens at Atlas rate (worse rate = spread is Atlas revenue)
      $tokensAtMarket = round($amountNgn / $marketRate, 8);
      $tokensAtAtlas  = round($amountNgn / $atlasRate,  8);
      $spreadTokens   = round($tokensAtMarket - $tokensAtAtlas, 8);
      $spreadNgn      = round($spreadTokens * $marketRate, 2);
      $userReceives   = $tokensAtAtlas . ' ' . $token;
    } else {
      // USDT → NGN: Atlas buys at market, gives user Atlas buy rate
      $tokensAtMarket = round($amountNgn / $marketRate, 8);
      $tokensAtAtlas  = round($amountNgn / $atlasRate,  8);
      $spreadTokens   = round($tokensAtMarket - $tokensAtAtlas, 8);
      $spreadNgn      = round($spreadTokens * $marketRate, 2);
      $userReceives   = '₦' . number_format($amountNgn - $spreadNgn - $flatFeeNgn, 2);
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
      'direction'            => $direction,
      'token'                => $token,
      'user_receives'        => $userReceives,
      'user_pays'            => '$0.05 + FX spread',
      'description'          => "Crypto conversion fee ({$direction})",
      'breakdown'            => "\$0.05 flat (₦{$flatFeeNgn}) + spread ₦{$spreadNgn} = ₦{$totalFeeNgn}",
    ];
  }

  // ── Crypto withdrawal fee ─────────────────────────────────────────────────
  public function cryptoWithdrawalFee(): array
  {
    $feeNgn = round(self::CRYPTO_WITHDRAW_FEE_USD * self::USD_NGN_RATE, 2);

    return [
      'fee_type'       => 'crypto_withdrawal',
      'fee_amount_ngn' => $feeNgn,
      'fee_usd'        => self::CRYPTO_WITHDRAW_FEE_USD,
      'fee_rate'       => self::CRYPTO_WITHDRAW_FEE_USD,
      'user_pays'      => '$0.50 (₦' . number_format($feeNgn) . ')',
      'description'    => 'Crypto withdrawal fee',
      'breakdown'      => '$0.50 × ₦' . number_format(self::USD_NGN_RATE) . '/$ = ₦' . number_format($feeNgn),
    ];
  }

  // ── Bill payment — ₦0 to user ─────────────────────────────────────────────
  public function billPaymentFee(): array
  {
    return $this->noFee('bill_payment');
  }

  // ── No fee ────────────────────────────────────────────────────────────────
  private function noFee(string $type = 'none'): array
  {
    return [
      'fee_type'       => $type,
      'fee_amount_ngn' => 0,
      'fee_rate'       => 0,
      'user_pays'      => '₦0',
      'description'    => 'No fee',
      'breakdown'      => '',
    ];
  }

  // ── Calculate fees for entire execution upfront ───────────────────────────
  // Returns fee breakdown for all steps — used in rule builder preview
  public function calculateForExecution(array $actions): array
  {
    $sendBankCount = collect($actions)->where('action_type', 'send_bank')->count();
    $breakdown     = [];
    $totalFeeNgn   = 0;

    // Bank transfer fee (calculated once for all send_bank steps combined)
    if ($sendBankCount > 0) {
      $fee = $this->bankTransferFee($sendBankCount);
      $breakdown[] = $fee;
      $totalFeeNgn += $fee['fee_amount_ngn'];
    }

    // Per-action fees for non-bank steps
    foreach ($actions as $action) {
      $type = $action['action_type'] ?? '';
      $amt  = (float) ($action['amount'] ?? 0);

      if ($type === 'convert_crypto') {
        $token = $action['config']['token'] ?? 'USDT';
        $fee   = $this->cryptoConversionFee($amt, $token);
        $breakdown[]  = $fee;
        $totalFeeNgn += $fee['fee_amount_ngn'];
      }

      if ($type === 'pay_bill') {
        $breakdown[] = $this->billPaymentFee();
        // ₦0 — no addition to total
      }
    }

    return [
      'total_fee_ngn' => $totalFeeNgn,
      'formatted'     => '₦' . number_format($totalFeeNgn, 2),
      'breakdown'     => $breakdown,
      'note'          => $this->feeNote($sendBankCount),
    ];
  }

  private function feeNote(int $sendBankCount): string
  {
    if ($sendBankCount === 0) return '';
    if ($sendBankCount >= self::FIAT_BULK_THRESHOLD) {
      return "Bulk discount applied — ₦10/transfer instead of ₦20 (5+ transfers)";
    }
    return "₦20 per bank transfer";
  }
}

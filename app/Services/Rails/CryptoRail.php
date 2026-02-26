<?php

namespace App\Services\Rails;

use App\Contracts\RailAdapterInterface;
use Illuminate\Support\Str;

/**
 * CryptoRail — Convert NGN to USDT on Tron (TRC-20).
 * Simulated for demo. Production: Binance P2P API or Yellow Card.
 */
class CryptoRail implements RailAdapterInterface
{
    // Simulated rate — in production: live rate from exchange
    private float $ngnUsdtRate = 1620.00;

  public function execute(array $config, float $amountNgn): array
  {
    $network   = $config['network']   ?? 'trc20';
    $token     = $config['token']     ?? 'USDT';
    $walletId  = $config['wallet_id'] ?? null;
    $wallet    = $config['wallet']    ?? 'DEMO_WALLET';
    $label     = $config['wallet_label'] ?? 'Wallet';

    // Simulated exchange rate
    $rates = [
      'USDT' => 1620,
      'BTC' => 98000000,
      'ETH' => 5200000,
      'BNB'  => 850000,
      'SOL' => 180000,
      'USDC' => 1620,
    ];
    $rateNgn  = $rates[$token] ?? 1620;
    $amountToken = bcdiv((string) $amountNgn, (string) $rateNgn, 8);

    $reference = 'CRYPTO_' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
    $txHash    = '0x' . bin2hex(random_bytes(32));

    usleep(500000); // Simulate blockchain confirmation

    $result = [
      'rail'          => 'crypto',
      'rail_reference' => $reference,
      'tx_hash'       => $txHash,
      'network'       => $network,
      'token'         => $token,
      'amount_token'  => $amountToken,
      'amount_ngn'    => (string) $amountNgn,
      'rate_ngn'      => $rateNgn,
      'wallet'        => $wallet,
      'wallet_label'  => $label,
      'confirmations' => 12,
      'status'        => 'success',
      'provider'      => 'simulated',
      'timestamp'     => now()->toISOString(),
    ];

    // ── Record crypto balance ─────────────────────────────────────────────
    try {
      $userId = auth()->id() ?? $config['user_id'] ?? null;
      if ($userId) {
        $cryptoBalance = \App\Models\CryptoBalance::firstOrNew([
          'user_id'    => $userId,
          'contact_id' => $walletId,
          'token'      => $token,
          'network'    => $network,
        ]);

        $cryptoBalance->wallet_label    = $label;
        $cryptoBalance->balance         = bcadd((string)($cryptoBalance->balance ?? 0), $amountToken, 8);
        $cryptoBalance->total_received  = bcadd((string)($cryptoBalance->total_received ?? 0), $amountToken, 8);
        $cryptoBalance->last_updated_at = now();
        $cryptoBalance->save();

        $result['new_balance'] = $cryptoBalance->balance . ' ' . $token;
      }
    } catch (\Throwable $e) {
      // Non-fatal — log but don't fail the execution
      \Illuminate\Support\Facades\Log::warning('CryptoRail: failed to update balance: ' . $e->getMessage());
    }

    return [
      'result'          => $result,
      'rail_reference'  => $reference,
      'rollback_payload' => [
        'type'         => 'crypto_reversal',
        'tx_hash'      => $txHash,
        'amount_token' => $amountToken,
        'amount_ngn'   => $amountNgn,
        'network'      => $network,
        'token'        => $token,
        'wallet_id'    => $walletId,
        'user_id'      => $config['user_id'] ?? null,
      ],
    ];
  }

    public function rollback(array $rollbackPayload): bool
    {
        // Production: swap USDT back to NGN via exchange
        return true;
    }
}

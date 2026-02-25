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

  public function execute(array $config, string $amountNgn): array
  {
    $usdtAmount = bcdiv($amountNgn, (string) $this->ngnUsdtRate, 6);
    $txHash     = '0x' . strtolower(Str::random(64));
    $reference  = 'CRYPTO_' . strtoupper(Str::random(10));

    usleep(500000); // Simulate blockchain confirmation

    $result = [
      'rail'           => 'crypto_trc20',
      'rail_reference' => $reference,
      'tx_hash'        => $txHash,
      'network'        => $config['network'] ?? 'tron',
      'token'          => 'USDT-TRC20',
      'amount_ngn'     => $amountNgn,
      'amount_usdt'    => $usdtAmount,
      'rate'           => $this->ngnUsdtRate . ' NGN/USDT',
      'wallet'         => $config['wallet'] ?? 'TRX_DEMO_WALLET',
      'confirmations'  => 12,
      'status'         => 'success',
      'provider'       => 'crypto_simulated',
      'timestamp'      => now()->toISOString(),
    ];

    return [
      'result'           => $result,
      'rail_reference'   => $reference,
      'rollback_payload' => [
        'type'        => 'crypto_reversal',
        'reference'   => $reference,
        'tx_hash'     => $txHash,
        'amount_usdt' => $usdtAmount,
        'amount_ngn'  => $amountNgn,
        'network'     => $config['network'] ?? 'tron',
      ],
    ];
  }

  public function rollback(array $rollbackPayload): bool
  {
    // Production: swap USDT back to NGN via exchange
    return true;
  }
}

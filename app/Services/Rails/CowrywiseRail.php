<?php

namespace App\Services\Rails;

use App\Contracts\RailAdapterInterface;
use Illuminate\Support\Str;

/**
 * CowrywiseRail — Push to Cowrywise investment plan.
 * Simulated for demo. Production: Cowrywise partnership API.
 */
class CowrywiseRail implements RailAdapterInterface
{
  public function execute(array $config, string $amountNgn): array
  {
    $reference = 'CW' . strtoupper(Str::random(14));

    usleep(300000);

    $result = [
      'rail'           => 'cowrywise',
      'rail_reference' => $reference,
      'plan'           => $config['plan'] ?? 'Halal Investment Plan',
      'amount_ngn'     => $amountNgn,
      'estimated_return' => '12% p.a.',
      'status'         => 'success',
      'provider'       => 'cowrywise_simulated',
      'timestamp'      => now()->toISOString(),
    ];

    return [
      'result'           => $result,
      'rail_reference'   => $reference,
      'rollback_payload' => [
        'type'       => 'cowrywise_redemption',
        'reference'  => $reference,
        'amount_ngn' => $amountNgn,
      ],
    ];
  }

  public function rollback(array $rollbackPayload): bool
  {
    return true;
  }
}

<?php

namespace App\Services\Rails;

use App\Contracts\RailAdapterInterface;
use Illuminate\Support\Str;

/**
 * PiggyVestRail — Push savings to PiggyVest.
 * Simulated for demo. Production: PiggyVest partnership API.
 */
class PiggyVestRail implements RailAdapterInterface
{
  public function execute(array $config, string $amountNgn): array
  {
    $reference = 'PV' . strtoupper(Str::random(14));

    usleep(300000); // Simulate API call

    $result = [
      'rail'           => 'piggyvest',
      'rail_reference' => $reference,
      'plan'           => $config['plan'] ?? 'Piggybank',
      'amount_ngn'     => $amountNgn,
      'note'           => $config['note'] ?? 'Atlas auto-save',
      'new_balance'    => number_format((float)$amountNgn + 150000, 2),
      'status'         => 'success',
      'provider'       => 'piggyvest_simulated',
      'timestamp'      => now()->toISOString(),
    ];

    return [
      'result'           => $result,
      'rail_reference'   => $reference,
      'rollback_payload' => [
        'type'       => 'piggyvest_withdrawal',
        'reference'  => $reference,
        'amount_ngn' => $amountNgn,
        'plan'       => $config['plan'] ?? 'Piggybank',
      ],
    ];
  }

  public function rollback(array $rollbackPayload): bool
  {
    // Production: PiggyVest withdrawal API
    return true;
  }
}

<?php

namespace App\Services\Rails;

use App\Contracts\RailAdapterInterface;
use Illuminate\Support\Str;

/**
 * BillsRail — Pay DSTV, electricity, airtime, etc.
 * Simulated for demo. Production: Flutterwave Bills API or Paystack Bills.
 */
class BillsRail implements RailAdapterInterface
{
  public function execute(array $config, string $amountNgn): array
  {
    $provider  = $config['provider'] ?? 'unknown';
    $reference = 'BILL' . strtoupper(Str::random(12));

    usleep(250000);

    $result = [
      'rail'           => 'bills',
      'rail_reference' => $reference,
      'provider'       => $provider,
      'amount_ngn'     => $amountNgn,
      'status'         => 'success',
      'provider_ref'   => strtoupper(Str::random(8)),
      'timestamp'      => now()->toISOString(),
    ];

    // Provider-specific details
    match ($provider) {
      'dstv', 'gotv', 'startimes' => $result += [
        'smart_card' => $config['smart_card'] ?? 'N/A',
        'package'    => $config['package'] ?? 'Premium',
        'validity'   => '30 days',
      ],
      'ekedc', 'ikedc', 'aedc' => $result += [
        'meter_number' => $config['meter_number'] ?? 'N/A',
        'units'        => round((float)$amountNgn / 85, 1) . ' kWh',
        'token'        => implode('-', str_split(strtoupper(Str::random(20)), 4)),
      ],
      'mtn', 'airtel', 'glo', '9mobile' => $result += [
        'phone'   => $config['phone'] ?? 'N/A',
        'network' => strtoupper($provider),
      ],
      default => null,
    };

    return [
      'result'           => $result,
      'rail_reference'   => $reference,
      'rollback_payload' => [
        'type'       => 'bills_reversal',
        'reference'  => $reference,
        'provider'   => $provider,
        'amount_ngn' => $amountNgn,
      ],
    ];
  }

  public function rollback(array $rollbackPayload): bool
  {
    // Bills are generally non-reversible in production
    // Flag for manual review instead
    return true;
  }
}

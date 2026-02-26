<?php

namespace App\Services\Rails;

use App\Contracts\RailAdapterInterface;
use Illuminate\Support\Str;

/**
 * BillRail — Pay bills via VTpass/Quickteller aggregator.
 * Simulated for demo. Production: VTpass API.
 * User pays ₦0 fee — Atlas earns 1-3% biller commission on backend.
 */
class BillRail implements RailAdapterInterface
{
  public function execute(array $config, float $amountNgn): array
  {
    $provider  = $config['provider'] ?? 'dstv';
    $reference = 'BILL' . strtoupper(Str::random(12));

    usleep(400000); // Simulate API call

    $providerLabels = [
      'dstv'      => 'DStv',
      'gotv'      => 'GOtv',
      'startimes' => 'Startimes',
      'ekedc'     => 'EKEDC (Electricity)',
      'ikedc'     => 'IKEDC (Electricity)',
      'aedc'      => 'AEDC (Electricity)',
      'mtn'       => 'MTN Airtime',
      'airtel'    => 'Airtel Airtime',
      'glo'       => 'Glo Airtime',
      '9mobile'   => '9mobile Airtime',
    ];

    // Simulated biller commission (1-3%) — Atlas earns this on backend
    $commissionRates = [
      'dstv' => 0.02,
      'gotv' => 0.02,
      'startimes' => 0.02,
      'ekedc' => 0.01,
      'ikedc' => 0.01,
      'aedc' => 0.01,
      'mtn' => 0.03,
      'airtel' => 0.03,
      'glo' => 0.03,
      '9mobile' => 0.03,
    ];

    $commissionRate = $commissionRates[$provider] ?? 0.015;
    $commissionNgn  = round($amountNgn * $commissionRate, 2);

    $result = [
      'rail'             => 'bill_payment',
      'rail_reference'   => $reference,
      'provider'         => $provider,
      'provider_label'   => $providerLabels[$provider] ?? strtoupper($provider),
      'smart_card'       => $config['smart_card'] ?? $config['meter_number'] ?? $config['phone'] ?? 'N/A',
      'package'          => $config['package'] ?? null,
      'amount_ngn'       => $amountNgn,
      'user_fee'         => 0,            // ₦0 to user
      'atlas_commission' => $commissionNgn, // Atlas earns this silently
      'status'           => 'success',
      'provider'         => 'vtpass_simulated',
      'timestamp'        => now()->toISOString(),
    ];

    return [
      'result'           => $result,
      'rail_reference'   => $reference,
      'rollback_payload' => [
        'type'       => 'bill_reversal',
        'reference'  => $reference,
        'amount_ngn' => $amountNgn,
        'provider'   => $provider,
      ],
    ];
  }

  public function rollback(array $rollbackPayload): bool
  {
    // Production: VTpass refund API
    return true;
  }
}

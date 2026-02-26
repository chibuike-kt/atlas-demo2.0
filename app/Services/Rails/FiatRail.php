<?php

namespace App\Services\Rails;

use App\Contracts\RailAdapterInterface;
use App\Models\SavedContact;
use App\Services\EncryptionService;
use Illuminate\Support\Str;

/**
 * FiatRail — NGN bank transfers via Nigerian payment rails.
 * Simulated for demo. Production: Paystack Transfer API or Flutterwave.
 */
class FiatRail implements RailAdapterInterface
{
  public function __construct(private readonly EncryptionService $encryption) {}

  public function execute(array $config, float $amountNgn): array
  {
    $contact = SavedContact::find($config['contact_id']);

    if (!$contact) {
      throw new \RuntimeException('Saved contact not found.');
    }

    $accountName   = $this->encryption->decrypt($contact->account_name_enc);
    $accountNumber = $this->encryption->decrypt($contact->account_number_enc);

    // Simulate transfer — in production: Paystack/Flutterwave Transfer API
    $reference = 'ATL' . strtoupper(Str::random(12));

    // Simulate 200ms processing delay
    usleep(200000);

    $result = [
      'rail'           => 'fiat_ngn',
      'rail_reference' => $reference,
      'recipient'      => [
        'name'           => $accountName,
        'account_number' => '******' . substr($accountNumber, -4),
        'bank'           => $contact->bank_name,
        'bank_code'      => $contact->bank_code,
      ],
      'amount_ngn'     => $amountNgn,
      'narration'      => $config['narration'] ?? 'Atlas transfer',
      'status'         => 'success',
      'provider'       => 'simulated',
      'timestamp'      => now()->toISOString(),
    ];

    return [
      'result'           => $result,
      'rail_reference'   => $reference,
      'rollback_payload' => [
        'type'           => 'fiat_reversal',
        'reference'      => $reference,
        'amount_ngn'     => $amountNgn,
        'contact_id'     => $contact->id,
        'account_number' => $accountNumber,
        'bank_code'      => $contact->bank_code,
      ],
    ];
  }

  public function rollback(array $rollbackPayload): bool
  {
    // Production: initiate reversal via PSP reversal API
    // Simulated: log and return true
    return true;
  }
}

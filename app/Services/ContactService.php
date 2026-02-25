<?php

namespace App\Services;

use App\Models\SavedContact;
use App\Models\User;

class ContactService
{
  public function __construct(private readonly EncryptionService $encryption) {}

  public function create(User $user, array $data): SavedContact
  {
    $payload = [
      'user_id' => $user->id,
      'label'   => $data['label'],
      'type'    => $data['type'] ?? 'bank',
    ];

    if ($payload['type'] === 'bank') {
      $payload['account_name_enc']   = $this->encryption->encrypt($data['account_name']);
      $payload['account_number_enc'] = $this->encryption->encrypt($data['account_number']);
      $payload['bank_code']          = $data['bank_code'];
      $payload['bank_name']          = $data['bank_name'];
    } else {
      $payload['wallet_address_enc'] = $this->encryption->encrypt($data['wallet_address']);
      $payload['crypto_network']     = $data['crypto_network'] ?? 'tron';
    }

    $contact = SavedContact::create($payload);

    activity()
      ->causedBy($user)
      ->performedOn($contact)
      ->log('contact.created');

    return $contact;
  }

  public function list(User $user): array
  {
    return $user->savedContacts()
      ->where('is_active', true)
      ->get()
      ->map(fn($c) => $this->format($c))
      ->toArray();
  }

  public function delete(User $user, string $id): void
  {
    $contact = $user->savedContacts()->findOrFail($id);
    $contact->update(['is_active' => false]);

    activity()
      ->causedBy($user)
      ->performedOn($contact)
      ->log('contact.deleted');
  }

  public function format(SavedContact $contact): array
  {
    $data = [
      'id'    => $contact->id,
      'label' => $contact->label,
      'type'  => $contact->type,
    ];

    if ($contact->type === 'bank') {
      $data['account_name']   = $this->safeDecrypt($contact->account_name_enc);
      $data['account_number'] = $this->maskAccount($contact->account_number_enc);
      $data['bank_code']      = $contact->bank_code;
      $data['bank_name']      = $contact->bank_name;
    } else {
      $data['wallet_address'] = $this->maskWallet($contact->wallet_address_enc);
      $data['crypto_network'] = $contact->crypto_network;
    }

    return $data;
  }

  /**
   * Simulate device contacts — pre-loaded Nigerian names for demo.
   * In production mobile app: replaced by native contacts API.
   */
  public function deviceContacts(): array
  {
    return [
      ['name' => 'Mama',          'phone' => '+2348012345678'],
      ['name' => 'Ada (Wife)',     'phone' => '+2348098765432'],
      ['name' => 'Chidi Brother', 'phone' => '+2347056789012'],
      ['name' => 'Landlord Musa', 'phone' => '+2348134567890'],
      ['name' => 'Tunde Office',  'phone' => '+2348023456789'],
      ['name' => 'Ngozi Sister',  'phone' => '+2347089012345'],
      ['name' => 'Pastor Emeka',  'phone' => '+2348167890123'],
      ['name' => 'Gym Trainer',   'phone' => '+2348045678901'],
    ];
  }

  /**
   * Nigerian banks list for the account input form.
   */
  public function bankList(): array
  {
    return [
      ['code' => '058', 'name' => 'Guaranty Trust Bank (GTB)'],
      ['code' => '044', 'name' => 'Access Bank'],
      ['code' => '057', 'name' => 'Zenith Bank'],
      ['code' => '011', 'name' => 'First Bank of Nigeria'],
      ['code' => '033', 'name' => 'United Bank for Africa (UBA)'],
      ['code' => '050', 'name' => 'Ecobank Nigeria'],
      ['code' => '070', 'name' => 'Fidelity Bank'],
      ['code' => '076', 'name' => 'Polaris Bank'],
      ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
      ['code' => '232', 'name' => 'Sterling Bank'],
      ['code' => '032', 'name' => 'Union Bank'],
      ['code' => '035', 'name' => 'Wema Bank'],
      ['code' => '301', 'name' => 'Jaiz Bank'],
      ['code' => '000026', 'name' => 'Taj Bank'],
      ['code' => '100004', 'name' => 'Opay'],
      ['code' => '100003', 'name' => 'PalmPay'],
      ['code' => '100002', 'name' => 'Kuda Bank'],
      ['code' => '100033', 'name' => 'Moniepoint'],
    ];
  }

  private function safeDecrypt(?string $encrypted): string
  {
    if (!$encrypted) return '';
    try {
      return $this->encryption->decrypt($encrypted);
    } catch (\Throwable) {
      return '';
    }
  }

  private function maskAccount(?string $encrypted): string
  {
    $number = $this->safeDecrypt($encrypted);
    if (!$number) return '**********';
    return '******' . substr($number, -4);
  }

  private function maskWallet(?string $encrypted): string
  {
    $address = $this->safeDecrypt($encrypted);
    if (!$address) return '**************';
    return substr($address, 0, 6) . '...' . substr($address, -4);
  }
}

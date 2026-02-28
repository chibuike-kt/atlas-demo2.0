<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
  use HasUuids;

  protected $fillable = [
    'user_id',
    'execution_id',
    'dispute_number',
    'reason',
    'description',
    'amount_ngn',
    'refund_amount',
    'status',
    'resolution_note',
    'opened_at',
    'resolved_at',
  ];

  protected function casts(): array
  {
    return [
      'amount_ngn'   => 'decimal:2',
      'refund_amount' => 'decimal:2',
      'opened_at'    => 'datetime',
      'resolved_at'  => 'datetime',
    ];
  }

  // Possible reasons shown to user in the UI
  public const REASONS = [
    'not_authorised'    => 'I did not authorise this transaction',
    'wrong_amount'      => 'Wrong amount was debited',
    'double_charge'     => 'I was charged twice',
    'not_received'      => 'Recipient did not receive the funds',
    'wrong_recipient'   => 'Money went to the wrong recipient',
    'crypto_not_received' => 'Crypto was not received in my wallet',
    'other'             => 'Other',
  ];

  public const STATUSES = [
    'open',
    'under_review',
    'resolved_refund',
    'resolved_no_action',
    'closed',
  ];

  public static function generateNumber(): string
  {
    $year  = date('Y');
    $month = date('m');
    $last  = static::whereYear('created_at', $year)
      ->whereMonth('created_at', $month)
      ->count() + 1;

    return sprintf('DIS-%s%s-%06d', $year, $month, $last);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function execution(): BelongsTo
  {
    return $this->belongsTo(RuleExecution::class, 'execution_id');
  }

  public function isOpen(): bool
  {
    return in_array($this->status, ['open', 'under_review']);
  }
}

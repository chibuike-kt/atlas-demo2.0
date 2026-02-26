<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'execution_id',
        'receipt_number',
        'total_amount',
        'total_fees',
        'status',
        'receipt_data',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:6',
            'total_fees'   => 'decimal:6',
            'receipt_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(RuleExecution::class, 'execution_id');
    }

    public static function generateReceiptNumber(): string
    {
        $year  = now()->format('Y');
        $month = now()->format('m');
        $last  = static::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count() + 1;
        return 'ATL-' . $year . $month . '-' . str_pad($last, 6, '0', STR_PAD_LEFT);
    }
}

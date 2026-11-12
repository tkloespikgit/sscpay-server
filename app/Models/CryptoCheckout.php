<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\CryptoCheckoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CryptoCheckout extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<CryptoCheckoutFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'order_id', 'payment_method_id', 'current_request_id', 'fee_snapshot',
        'status', 'action', 'phase', 'requested_at', 'handoff_at', 'ready_at', 'next_attempt_at', 'error_code',
    ];

    protected function casts(): array
    {
        return ['fee_snapshot' => 'array', 'requested_at' => 'immutable_datetime', 'handoff_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $checkout): void {
            if ($checkout->isDirty(['merchant_id', 'order_id', 'payment_method_id', 'fee_snapshot'])) {
                throw new LogicException('Crypto checkout ownership and contracted fees are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Crypto checkout audit cannot be deleted.'));
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CryptoPaymentRequest extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->isDirty(['replaces_request_id', 'merchant_id', 'order_id', 'payment_method_id', 'crypto_address_id', 'network', 'asset', 'decimals', 'due_units', 'fee_units', 'net_units', 'quote_snapshot', 'expires_at'])) {
                throw new \LogicException('Payment request binding and quote are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Payment request audit cannot be deleted.'));
    }

    protected $fillable = [
        'replaces_request_id',
        'history_to_block', 'history_to_hash', 'history_to_time', 'history_recent_blocks', 'history_checked_at',
        'history_next_attempt_at', 'history_error_code', 'review_reason', 'settlement_next_attempt_at',
        'current_monitor_id',
        'merchant_id',
        'order_id',
        'payment_method_id',
        'crypto_address_id',
        'network',
        'asset',
        'decimals',
        'status',
        'due_units',
        'fee_units',
        'net_units',
        'quote_snapshot',
        'expires_at',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'history_to_block' => 'integer', 'history_to_time' => 'immutable_datetime', 'history_recent_blocks' => 'array',
            'history_checked_at' => 'immutable_datetime', 'history_next_attempt_at' => 'immutable_datetime',
            'settlement_next_attempt_at' => 'immutable_datetime',
            'decimals' => 'integer',
            'quote_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function cryptoAddress(): BelongsTo
    {
        return $this->belongsTo(CryptoAddress::class);
    }

    public function chainEvents(): HasMany
    {
        return $this->hasMany(CryptoChainEvent::class);
    }
}

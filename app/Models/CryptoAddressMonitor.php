<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One immutable subscription generation. Renewal creates a new row. */
class CryptoAddressMonitor extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'crypto_payment_request_id', 'crypto_address_id', 'generation', 'callback_uuid', 'callback_url',
        'network', 'environment', 'credential_fingerprint', 'subscription_id', 'status', 'ttl_seconds', 'attempts',
        'last_error_code', 'next_attempt_at', 'activated_at', 'expires_at', 'cancelled_at',
        'backfill_to_block', 'backfill_to_hash', 'backfill_completed_at', 'cold_scan_to_block', 'cold_scan_to_hash', 'cold_scan_checked_at',
    ];

    protected $hidden = ['credential_fingerprint'];

    protected function casts(): array
    {
        return [
            'generation' => 'integer', 'ttl_seconds' => 'integer', 'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'backfill_to_block' => 'integer', 'backfill_completed_at' => 'immutable_datetime',
            'cold_scan_to_block' => 'integer', 'cold_scan_checked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $monitor): void {
            if ($monitor->isDirty(['merchant_id', 'crypto_payment_request_id', 'crypto_address_id', 'generation', 'callback_uuid', 'callback_url', 'network', 'environment', 'credential_fingerprint', 'ttl_seconds'])
                || ($monitor->getOriginal('subscription_id') !== null && $monitor->isDirty('subscription_id'))
                || ($monitor->getOriginal('activated_at') !== null && $monitor->isDirty(['activated_at', 'expires_at']))) {
                throw new LogicException('Subscription identity and lifetime snapshots cannot be rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('Subscription history cannot be deleted.'));
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(CryptoPaymentRequest::class, 'crypto_payment_request_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(CryptoAddress::class, 'crypto_address_id');
    }
}

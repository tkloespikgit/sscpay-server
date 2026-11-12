<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Support\CryptoAmount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoChainEvent extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (! preg_match('/^(?:0x)?[a-fA-F0-9]{64}$/D', $event->transaction_hash)
                || ! preg_match('/^[a-z0-9_:.\/-]{1,128}$/D', $event->event_position)) {
                throw new \InvalidArgumentException('Invalid chain event identity.');
            }
            CryptoAmount::units($event->amount_units);
            $event->transaction_hash = strtolower(preg_replace('/^0x/i', '', $event->transaction_hash));
            $event->event_key = hash('sha256', $event->network.':'.$event->transaction_hash.':'.$event->event_position);
        });
        static::updating(function (self $event): void {
            if ($event->isDirty(['merchant_id', 'crypto_payment_request_id', 'network', 'asset', 'transaction_hash', 'event_position', 'event_key', 'amount_units'])) {
                throw new \LogicException('Chain event identity and value cannot be reassigned.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Chain event audit cannot be deleted.'));
    }

    protected $fillable = [
        'merchant_id',
        'crypto_payment_request_id',
        'network',
        'asset',
        'transaction_hash',
        'event_position',
        'event_key',
        'amount_units',
        'block_hash',
        'block_height',
        'block_time',
        'verified_at',
        'confirmed_at',
        'orphaned_at',
    ];

    protected function casts(): array
    {
        return [
            'block_height' => 'integer',
            'block_time' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'orphaned_at' => 'immutable_datetime',
        ];
    }

    public function cryptoPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(CryptoPaymentRequest::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }
}

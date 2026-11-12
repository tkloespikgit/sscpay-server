<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Support\CryptoAssetRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoAddress extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'crypto_address_allocation_id',
        'merchant_id',
        'order_id',
        'payment_method_id',
        'network',
        'address',
        'address_key',
        'source_fingerprint',
        'derivation_index',
        'scan_from_block',
    ];

    protected function casts(): array
    {
        return [
            'derivation_index' => 'integer',
            'scan_from_block' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $address): void {
            $address->address_key = (new CryptoAssetRegistry)->addressKey($address->network, $address->address);
        });
        static::updating(fn () => throw new \LogicException('Crypto audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Crypto audit records cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}

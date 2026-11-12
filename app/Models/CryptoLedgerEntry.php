<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoLedgerEntry extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'order_id',
        'crypto_payment_request_id',
        'crypto_asset_balance_id',
        'idempotency_key',
        'type',
        'network',
        'asset',
        'gross_units',
        'fee_units',
        'amount_units',
        'balance_before_units',
        'balance_after_units',
        'fee_snapshot',
        'debt_before_units',
        'debt_after_units',
    ];

    protected function casts(): array
    {
        return [
            'fee_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Crypto audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Crypto audit records cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function cryptoPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(CryptoPaymentRequest::class);
    }

    public function cryptoAssetBalance(): BelongsTo
    {
        return $this->belongsTo(CryptoAssetBalance::class);
    }
}

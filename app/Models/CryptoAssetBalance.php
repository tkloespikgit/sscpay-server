<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Support\CryptoAmount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CryptoAssetBalance extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'network',
        'asset',
        'balance_units',
        'reserved_units',
        'debt_units',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $balance): void {
            if ($balance->isDirty(['merchant_id', 'network', 'asset'])) {
                throw new LogicException('Crypto balance ownership and denomination are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Crypto balances cannot be deleted.'));
    }

    public function availableUnits(): string
    {
        if (bccomp($this->debt_units ?? '0', '0', 0) > 0) {
            return '0';
        }

        return CryptoAmount::units(bcsub($this->balance_units, $this->reserved_units, 0));
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }
}

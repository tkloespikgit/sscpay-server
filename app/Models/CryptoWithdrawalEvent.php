<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CryptoWithdrawalEvent extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'crypto_withdrawal_id', 'crypto_asset_balance_id', 'operator_id', 'action',
        'debt_before_units', 'debt_after_units', 'from_status', 'to_status', 'balance_before_units', 'balance_after_units',
        'reserved_before_units', 'reserved_after_units', 'reason', 'manual_reference',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Crypto withdrawal events are append-only.'));
        static::deleting(fn () => throw new LogicException('Crypto withdrawal events are append-only.'));
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id')->withTrashed();
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(CryptoWithdrawal::class, 'crypto_withdrawal_id');
    }
}

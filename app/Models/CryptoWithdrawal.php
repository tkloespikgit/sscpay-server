<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CryptoWithdrawal extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'crypto_asset_balance_id', 'request_key', 'network', 'asset', 'decimals',
        'amount_units', 'fee_limit_units', 'reserved_units', 'actual_fee_units', 'destination_address',
        'status', 'remark', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_remark',
        'completed_by', 'completed_at', 'manual_reference', 'completion_remark',
    ];

    protected function casts(): array
    {
        return ['decimals' => 'integer', 'reviewed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $withdrawal): void {
            if (in_array($withdrawal->getOriginal('status'), ['completed', 'rejected', 'cancelled'], true) && $withdrawal->isDirty()) {
                throw new LogicException('Finalized crypto withdrawals are immutable.');
            }
            if ($withdrawal->isDirty(['merchant_id', 'crypto_asset_balance_id', 'request_key', 'network', 'asset',
                'decimals', 'amount_units', 'fee_limit_units', 'reserved_units', 'destination_address', 'requested_by', 'remark'])) {
                throw new LogicException('Crypto withdrawal request facts are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Crypto withdrawals cannot be deleted.'));
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function cryptoAssetBalance(): BelongsTo
    {
        return $this->belongsTo(CryptoAssetBalance::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(CryptoWithdrawalEvent::class)->orderBy('id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoAddressAllocation extends Model
{
    use BelongsToMerchant;
    use HasFactory;

    protected $fillable = ['merchant_id', 'order_id', 'payment_method_id', 'crypto_public_account_id', 'allocation_key', 'derivation_index', 'network', 'asset', 'status', 'address', 'address_key', 'public_config_snapshot', 'recovery_checked_at'];

    protected $hidden = ['public_config_snapshot'];

    protected function casts(): array
    {
        return ['public_config_snapshot' => 'array', 'derivation_index' => 'integer', 'recovery_checked_at' => 'immutable_datetime'];
    }

    public function publicAccount(): BelongsTo
    {
        return $this->belongsTo(CryptoPublicAccount::class, 'crypto_public_account_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            if ($allocation->isDirty(['merchant_id', 'order_id', 'payment_method_id', 'crypto_public_account_id', 'allocation_key', 'derivation_index', 'network', 'asset', 'public_config_snapshot'])
                || ($allocation->getOriginal('status') === 'derived' && $allocation->isDirty(['address', 'address_key', 'status']))) {
                throw new \LogicException('An address allocation cannot be reassigned.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Consumed address allocations cannot be deleted.'));
    }
}

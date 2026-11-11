<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use App\Services\MerchantPaymentSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MerchantPaymentMethod extends Pivot
{
    public $incrementing = true;

    protected $table = 'merchant_payment_methods';

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $merchant = Merchant::query()->find((int) $assignment->merchant_id);
            $method = PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)
                ->find((int) $assignment->payment_method_id);

            if ($merchant === null || $method === null || ! $method->canAssignToMerchant($merchant)) {
                throw new AuthorizationException;
            }
        });

        static::created(function (self $assignment): void {
            app(MerchantPaymentSettingsService::class)->initializeForAssignment(
                (int) $assignment->merchant_id,
                (int) $assignment->payment_method_id,
            );
        });
    }

    /**
     * 先锁商户再写分配，避免外键共享锁与默认配置行锁在并发分配时相互升级。
     */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            Merchant::query()->whereKey((int) $this->merchant_id)->lockForUpdate()->first();
            PaymentMethod::query()->withoutGlobalScope(MerchantScope::class)
                ->whereKey((int) $this->payment_method_id)->lockForUpdate()->first();

            return parent::save($options);
        });
    }
}

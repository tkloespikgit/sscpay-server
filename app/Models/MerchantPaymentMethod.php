<?php

namespace App\Models;

use App\Services\MerchantPaymentSettingsService;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MerchantPaymentMethod extends Pivot
{
    public $incrementing = true;

    protected $table = 'merchant_payment_methods';

    protected static function booted(): void
    {
        static::created(function (self $assignment): void {
            app(MerchantPaymentSettingsService::class)->initializeForAssignment(
                (int) $assignment->merchant_id,
                (int) $assignment->payment_method_id,
            );
        });
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }
}

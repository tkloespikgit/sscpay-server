<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 支付类型配置模板（超管专属，见 PaymentMethodConfigMapResource）。全平台共用，
 * 不挂 merchant_id——不同商户建 PaymentMethod 时共享同一套 Stripe/PayPal/Airwallex
 * 模板，只是各自填的 config 值不同。
 */
class PaymentMethodConfigMap extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'payment_config_tag',
        'is_active',
        'fields',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fields' => 'array',
        ];
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'config_map_id');
    }
}

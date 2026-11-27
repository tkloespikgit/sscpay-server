<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 退款单。支持一单多次部分退款，累计退款额由 Order::refunded_amount 封顶
 * （<= 订单原币种金额）。金额按订单原币种记录，amount_usd 为实际退款折算 USD。
 * 退款对余额的实际扣减 = principal_deducted_usd + fee，由 BalanceService 落两条流水。
 */
class OrderRefund extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id',
        'order_id',
        'currency',
        'amount',
        'exchange_rate',
        'amount_usd',
        'principal_deducted_usd',
        'fee',
        'operator_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'amount_usd' => 'decimal:2',
            'principal_deducted_usd' => 'decimal:2',
            'fee' => 'decimal:2',
        ];
    }

    public function gatewayRefund(): HasOne
    {
        return $this->hasOne(GatewayRefund::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}

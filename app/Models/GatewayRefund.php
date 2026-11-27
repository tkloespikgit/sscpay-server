<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayRefund extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'order_id', 'payment_method_id', 'identity_hash', 'order_refund_hash',
        'gateway_reference', 'payment_method', 'livemode', 'refund_id', 'payment_reference',
        'amount', 'currency', 'refunded_at', 'status', 'last_error', 'next_attempt_at', 'order_refund_id', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'livemode' => 'boolean', 'refunded_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'review']);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CombinedOrder extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id', 'application_id', 'payment_group_id', 'checkout_link_id', 'order_no',
        'merchant_order_no', 'source', 'platform', 'currency', 'converted_currency', 'original_amount',
        'auto_discount', 'amount', 'converted_amount', 'paid_amount', 'paid_converted_amount',
        'remaining_amount', 'remaining_converted_amount', 'exchange_rate', 'exchange_snapshot',
        'order_snapshot', 'status', 'current_quote', 'current_order_id', 'next_sequence', 'version',
        'webhook_version', 'payment_link_token', 'checkout_host', 'checkout_origin', 'notify_url', 'return_url', 'cancel_url',
        'send_mail', 'payment_link_sent_at', 'payment_link_mail_failed_reason', 'first_paid_at',
        'paid_at', 'expires_at', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2', 'auto_discount' => 'decimal:2', 'amount' => 'decimal:2',
            'converted_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'paid_converted_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2', 'remaining_converted_amount' => 'decimal:2', 'exchange_rate' => 'decimal:6',
            'exchange_snapshot' => 'array', 'order_snapshot' => 'array', 'current_quote' => 'array',
            'current_order_id' => 'integer', 'merchant_id' => 'integer', 'application_id' => 'integer',
            'version' => 'integer', 'next_sequence' => 'integer', 'webhook_version' => 'integer', 'send_mail' => 'boolean',
            'first_paid_at' => 'datetime', 'paid_at' => 'datetime', 'expires_at' => 'datetime', 'payment_link_sent_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('combined_sequence');
    }

    public function currentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'current_order_id');
    }

    public function getPayUrlAttribute(): string
    {
        return rtrim((string) $this->checkout_origin, '/').route('combined-payment.show', ['token' => $this->payment_link_token], absolute: false);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['pending', 'partially_paid'], true)
            && $this->expires_at->isFuture() && bccomp($this->remaining_amount, '0', 2) > 0;
    }

    /** 仅供既有邮件模板渲染，不保存这份订单对象。 */
    public function mailOrder(): Order
    {
        $order = new Order(array_merge(array_intersect_key($this->order_snapshot, array_flip((new Order)->getFillable())), $this->only([
            'merchant_id', 'application_id', 'order_no', 'merchant_order_no', 'currency', 'amount', 'converted_amount', 'payment_link_token',
        ]), ['pay_url' => $this->pay_url]));
        $order->created_at = $this->created_at->copy();
        $order->setRelation('application', $this->application);
        $order->setRelation('merchant', $this->merchant);

        return $order;
    }
}

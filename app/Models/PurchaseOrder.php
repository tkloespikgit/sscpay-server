<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_method_id',
        'created_by',
        'order_no',
        'transaction_id',
        'currency',
        'ordered_at',
        'paid_at',
        'shipped_at',
        'received_at',
        'subtotal',
        'shipping_fee',
        'discount',
        'total',
        'perks_words',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'perks_words' => 'array',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}

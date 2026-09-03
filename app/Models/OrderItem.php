<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * 不需要 BelongsToMerchant：本表没有 merchant_id 字段，
     * 隔离性由 belongsTo(Order) 间接保证（只能通过已隔离的 Order 访问到）。
     */
    protected $fillable = [
        'order_id',
        'product_sku',
        'product_id',
        'product_url',
        'product_name',
        'product_description',
        'unit_price',
        'quantity',
        'total_price',
        'converted_unit_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'total_price' => 'decimal:2',
            'converted_unit_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * 校验约束（3.8 节）：订单主表 subtotal 必须等于所有明细 total_price 之和。
     * 供 OrderCreation 相关 Service 在写入前后做二次校验。
     */
    public static function sumTotalPrice(array $items): string
    {
        $sum = '0';

        foreach ($items as $item) {
            $lineTotal = bcmul((string) $item['unit_price'], (string) $item['quantity'], 2);
            $sum = bcadd($sum, $lineTotal, 2);
        }

        return $sum;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 订单自动匹配商品：下单时未传商品明细的订单，由系统按订单商品金额
 * （USD）从支付方式绑定的站点商品中自动匹配出来的明细，
 * 表结构与 order_items 一致，单独存放以便和商户真实下单明细区分。
 */
class OrderMatchedItem extends Model
{
    use HasFactory;
    use SoftDeletes;

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
        // CREATE 匹配模式扩展：复制源变体 + 是否站点自动创建（见对应迁移）。
        'source_variation_id',
        'auto_created',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'total_price' => 'decimal:2',
            'converted_unit_price' => 'decimal:2',
            'source_variation_id' => 'integer',
            'auto_created' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

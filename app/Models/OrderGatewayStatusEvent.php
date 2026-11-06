<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderGatewayStatusEvent extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'order_id', 'gateway_reference', 'source', 'status',
        'gateway_updated_at', 'outcome', 'payload',
    ];

    protected function casts(): array
    {
        return ['gateway_updated_at' => 'datetime', 'payload' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}

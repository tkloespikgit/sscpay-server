<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShipmentSyncAttempt extends Model
{
    use BelongsToMerchant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'attempt_number' => 'integer',
            'response_status' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(OrderShipmentSync::class, 'order_shipment_sync_id');
    }
}

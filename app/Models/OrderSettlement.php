<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSettlement extends Model
{
    use BelongsToMerchant;

    public const STATUS_WAITING_SHIPMENT = 'waiting_shipment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'merchant_id', 'order_id', 'amount', 'remaining_amount', 'reserve_amount',
        'available_amount', 'mode', 'cycle_days', 'reserve_percent', 'reserve_days',
        'status', 'started_at', 'due_at', 'settled_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'reserve_amount' => 'decimal:2',
            'available_amount' => 'decimal:2',
            'reserve_percent' => 'decimal:4',
            'cycle_days' => 'integer',
            'reserve_days' => 'integer',
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}

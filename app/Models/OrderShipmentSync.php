<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderShipmentSync extends Model
{
    use BelongsToMerchant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'synced_at' => 'datetime',
            'alerted_at' => 'datetime',
            'next_alert_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(OrderShipmentSyncAttempt::class)->orderBy('attempt_number');
    }
}

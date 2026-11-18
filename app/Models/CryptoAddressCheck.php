<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\CryptoAddressCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CryptoAddressCheck extends Model
{
    use BelongsToMerchant;

    /** @use HasFactory<CryptoAddressCheckFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $check): void {
            if ($check->isDirty(['merchant_id', 'order_id', 'kind', 'check_key', 'cancelled_at', 'due_at', 'requested_by'])) {
                throw new \LogicException('Address check identity and schedule are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Address check audit cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'attempts' => 'integer', 'failure_count' => 'integer', 'result' => 'array',
        ];
    }
}

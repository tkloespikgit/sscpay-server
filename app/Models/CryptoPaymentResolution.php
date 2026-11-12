<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CryptoPaymentResolution extends Model
{
    use BelongsToMerchant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence_snapshot' => 'array', 'fee_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Crypto resolutions are immutable.'));
        static::deleting(fn () => throw new LogicException('Crypto resolutions cannot be deleted.'));
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class)->withTrashed();
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id')->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

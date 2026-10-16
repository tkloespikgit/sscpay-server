<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPaymentSetting extends Model
{
    use BelongsToMerchant;

    public const FINANCIAL_FIELDS = ['fee_percent', 'fee_fixed', 'refund_fee', 'chargeback_fee', 'reserve_percent', 'reserve_days'];

    protected $fillable = ['merchant_id', 'payment_method_id', ...self::FINANCIAL_FIELDS];

    protected function casts(): array
    {
        return [
            'fee_percent' => 'decimal:4',
            'fee_fixed' => 'decimal:2',
            'refund_fee' => 'decimal:2',
            'chargeback_fee' => 'decimal:2',
            'reserve_percent' => 'decimal:4',
            'reserve_days' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }
}

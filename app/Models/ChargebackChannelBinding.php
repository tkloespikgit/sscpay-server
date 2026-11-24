<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargebackChannelBinding extends Model
{
    public const ARN = 'arn';

    public const PSP_TRANSACTION = 'psp_transaction';

    public const GATEWAY_REFERENCE = 'gateway_reference';

    public const INVOICE = 'invoice';

    protected $fillable = [
        'chargeback_provider_account_id', 'payment_method_id', 'service', 'scope_key', 'scope_hash',
        'reference_type', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime'];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }
}

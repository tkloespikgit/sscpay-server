<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethodProfile extends Model
{
    protected $fillable = [
        'payment_method_id',
        'stock_addresses',
        'company_name',
        'legal_representative_name',
        'legal_representative_phone',
        'account_email',
        'company_address',
        'supplier_name',
        'supplier_phone',
        'supplier_email',
        'supplier_address',
        'supplier_contact_person',
    ];

    protected function casts(): array
    {
        return [
            'stock_addresses' => 'array',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}

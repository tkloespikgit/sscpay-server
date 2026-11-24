<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;

class OrderChargebackReference extends Model
{
    use BelongsToMerchant;

    protected $fillable = ['order_id', 'merchant_id', 'operator_id', 'gateway_reference', 'arn', 'arn_hash'];
}

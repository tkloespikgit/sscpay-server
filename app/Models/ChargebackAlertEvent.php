<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargebackAlertEvent extends Model
{
    protected $fillable = ['chargeback_alert_id', 'operator_id', 'kind', 'source', 'fingerprint', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}

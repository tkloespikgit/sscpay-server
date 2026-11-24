<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargebackProviderSetting extends Model
{
    protected $fillable = ['chargeback_provider_account_id', 'service', 'external_id', 'scope_key', 'remote_status', 'details', 'observed_at', 'observed_ms'];

    protected function casts(): array
    {
        return ['details' => 'array', 'observed_at' => 'datetime', 'observed_ms' => 'integer'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChargebackProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'name', 'provider', 'external_id', 'environment', 'timezone', 'signing_config',
        'services', 'enabled', 'sync_cursor', 'next_sync_at', 'last_sync_at', 'sync_error',
    ];

    protected $hidden = ['signing_config', 'sync_cursor'];

    protected function casts(): array
    {
        return [
            'signing_config' => 'encrypted:array',
            'enabled' => 'boolean',
            'services' => 'array',
            'sync_cursor' => 'array',
            'next_sync_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(ChargebackChannelBinding::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(ChargebackProviderSetting::class);
    }
}

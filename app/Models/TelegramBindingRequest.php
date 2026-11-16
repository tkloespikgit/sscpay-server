<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Model;

class TelegramBindingRequest extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'user_id', 'token_hash', 'bot_fingerprint',
        'chat_type', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['token_hash', 'bot_fingerprint'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}

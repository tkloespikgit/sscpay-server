<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TelegramBindingRequest extends Model
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'target_user_id', 'user_id', 'token_hash', 'bot_fingerprint',
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

    public function scopeForAdmin(Builder $query, int $userId): Builder
    {
        return $query->withoutGlobalScope(MerchantScope::class)
            ->where('target_user_id', $userId)
            ->whereNull('merchant_id');
    }
}

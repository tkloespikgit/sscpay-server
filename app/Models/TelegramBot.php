<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TelegramBot extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'bot_token',
        'chat_id',
        'is_enabled',
        'last_sent_at',
        'test_sent_at',
        'uses_platform_bot',
        'chat_title',
        'chat_type',
    ];

    protected $hidden = ['bot_token'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'uses_platform_bot' => 'boolean',
            'last_sent_at' => 'datetime',
            'test_sent_at' => 'datetime',
            // 内置 encrypted cast：读取自动解密、写入自动加密，
            // 不需要手写 setBotTokenAttribute()/getBotTokenAttribute()。
            'bot_token' => 'encrypted',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * 仅完成快速绑定的商户使用平台机器人；自建机器人不回退平台凭证。
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && filled($this->effectiveToken()) && filled($this->chat_id);
    }

    public function effectiveToken(): string
    {
        return (string) ($this->uses_platform_bot ? config('services.telegram.bot_token') : $this->bot_token);
    }
}

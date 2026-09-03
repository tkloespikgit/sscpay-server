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
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
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
     * 按业务决定（4.4 节的更新版）：商户未配置或未启用时，直接跳过通知，
     * 不再回退系统默认 Bot Token。TelegramNotificationService 应该先调用
     * 这个方法判断，为 false 就直接 return，不发起任何 HTTP 请求。
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && ! empty($this->bot_token) && ! empty($this->chat_id);
    }
}

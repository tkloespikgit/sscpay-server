<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * 商户物流跟踪对接配置（每商户至多一条有效记录）。
 *
 * 商户自选 driver / 可选 base_url / API Token / Webhook Secret；
 * webhook_token 用于公开回调路径定位商户。平台是否开放该驱动由 Manager 白名单再校验。
 * 无独立「启用」开关：凭证齐全 + 平台开放 + 订单快照即可使用。
 */
class MerchantLogisticsTrackingSetting extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'driver',
        'base_url',
        'api_token',
        'webhook_secret',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * 已选驱动且凭证齐全；平台是否开放该驱动由 SyncService gates 再校验。
     */
    public function isUsable(): bool
    {
        return filled($this->driver)
            && filled($this->api_token)
            && filled($this->webhook_secret)
            && filled($this->webhook_token);
    }

    /** 生成写入公开 Webhook URL 的随机令牌。 */
    public static function generateWebhookToken(): string
    {
        return Str::lower(Str::random(40));
    }

    /**
     * 拼装 Provider 控制台应配置的回调地址：
     * /api/webhooks/logistics-tracking/{driver}/{webhook_token}
     */
    public function webhookUrl(?string $driver = null): string
    {
        $driver = $driver ?: (string) $this->driver ?: 'seventeen_track';

        return url('/api/webhooks/logistics-tracking/'.$driver.'/'.$this->webhook_token);
    }
}

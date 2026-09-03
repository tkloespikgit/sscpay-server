<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Application extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'name',
        'website',
        'remark',
        'app_id',
        'api_key',
        'is_order_email_enabled',
        'sender_email',
        'sender_name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_order_email_enabled' => 'boolean',
            'status' => 'boolean',
            // 使用 Laravel 内置的 encrypted cast：读取时自动解密、写入时自动加密，
            // 不需要在业务代码里手写 Crypt::encryptString()/decrypt()。
            // 注意：ApiAuthentication 中间件里直接访问 $application->api_key 拿到的
            // 就已经是明文，不要再对它调用 decrypt()。
            'api_key' => 'encrypted',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * 生成一对新的 API 凭证（app_id 全局唯一，api_key 为随机密钥，落库时由
     * encrypted cast 自动加密）。
     */
    public static function generateCredentials(): array
    {
        return [
            'app_id' => 'APP_'.Str::upper(Str::random(12)),
            'api_key' => 'KEY_'.Str::random(32).'_'.now()->timestamp,
        ];
    }

    /**
     * 创建应用并自动生成凭证。传入的 $data 不应包含 app_id / api_key，
     * 这两个字段由本方法统一生成，避免调用方误传导致冲突或弱密钥。
     */
    public static function createWithCredentials(array $data): self
    {
        $credentials = static::generateCredentials();

        return static::create(array_merge(
            $data,
            $credentials
        ));
    }

    /**
     * 校验发件人邮箱域名是否与 website 字段匹配（如 sender_email = notify@hat.com
     * 时 website 应为 hat.com 或其子域）。仅用于后台表单校验提示，不是强制业务规则。
     */
    public function senderDomainMatchesWebsite(): bool
    {
        if (! $this->sender_email || ! $this->website) {
            return true;
        }

        $senderDomain = strtolower(substr(strrchr($this->sender_email, '@'), 1));
        $website = strtolower(preg_replace('#^https?://#', '', $this->website));

        return $senderDomain === $website || str_ends_with($senderDomain, '.'.$website);
    }
}

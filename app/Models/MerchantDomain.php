<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Services\Checkout\CloudflareSaasService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * 商户绑定的自有域名，收款链接落地页跑在上面。
 *
 * 两段式流程，缺一不可：
 *   1. DNS 归属验证（verified_at）——证明商户确实控制这个域名；
 *   2. Cloudflare for SaaS 自定义主机名（cf_hostname_id / cf_ssl_status）
 *      ——让 CF 边缘给这个域名签发证书，HTTPS 才能生效。
 *
 * 只有两段都完成（isReady()）的域名才会被落地页路由接受。
 */
class MerchantDomain extends Model
{
    use BelongsToMerchant;
    use HasFactory;
    use SoftDeletes;

    /** CF 侧证书已签发并生效，此时域名才真正可用。 */
    public const CF_STATUS_ACTIVE = 'active';

    protected $fillable = [
        'merchant_id',
        'host',
        'verify_token',
        'verified_at',
        'last_verify_error',
        'cf_hostname_id',
        'cf_ssl_status',
        'cf_last_error',
        'cf_dcv_records',
        'cf_synced_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'cf_synced_at' => 'datetime',
            'cf_dcv_records' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $domain) {
            $domain->host = static::normalizeHost($domain->host);

            if (blank($domain->verify_token)) {
                $domain->verify_token = static::generateVerifyToken();
            }
        });

        // host 改了等于换了一个域名：之前的验证结果和 CF 自定义主机名都不再适用，
        // 必须整个作废重来，否则会出现"验证的是 A 域名、实际服务的是 B 域名"。
        static::updating(function (self $domain) {
            $domain->host = static::normalizeHost($domain->host);

            if (! $domain->isDirty('host')) {
                return;
            }

            // 必须先删除旧 CF 主机名；失败时中止更新，保留旧 ID 供重试。
            if (filled($domain->cf_hostname_id)) {
                app(CloudflareSaasService::class)->deleteOrFail($domain);
            }

            $domain->verify_token = static::generateVerifyToken();
            $domain->verified_at = null;
            $domain->last_verify_error = null;
            $domain->cf_hostname_id = null;
            $domain->cf_ssl_status = null;
            $domain->cf_last_error = null;
            $domain->cf_dcv_records = null;
            $domain->cf_synced_at = null;
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function checkoutLinks(): HasMany
    {
        return $this->hasMany(CheckoutLink::class);
    }

    /**
     * 归一化：去掉协议、路径、端口，转小写，去掉结尾的点。
     * 商户复制粘贴过来的往往是 "https://Checkout.TVBox.com/" 这种形式，
     * 不归一化的话按 Host 头查表永远查不到。
     */
    public static function normalizeHost(?string $value): string
    {
        $host = trim((string) $value);
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        // IPv6 字面量不在支持范围内（商户绑的一定是域名），这里直接按 host:port 切。
        $host = explode(':', $host)[0];

        return rtrim(strtolower($host), '.');
    }

    public static function generateVerifyToken(): string
    {
        return 'sscpay-verify='.Str::random(40);
    }

    /**
     * 商户需要添加的 TXT 记录名。用 _sscpay-challenge 子名而不是直接打在根 host 上，
     * 避免和商户自己已有的 SPF/DMARC 等 TXT 记录互相干扰。
     */
    public function verifyRecordName(): string
    {
        return '_sscpay-challenge.'.$this->host;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isCertificateActive(): bool
    {
        return $this->cf_ssl_status === self::CF_STATUS_ACTIVE;
    }

    /**
     * 域名是否可以真正对外服务：启用中 + DNS 归属已验证 + CF 证书已生效。
     * 少任何一环，客户要么打不开（无证书），要么可能被劫持（未验证归属）。
     */
    public function isReady(): bool
    {
        return $this->is_active && $this->isVerified() && $this->isCertificateActive();
    }
}

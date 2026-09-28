<?php

namespace App\Services\Checkout;

use App\Models\MerchantDomain;
use Illuminate\Support\Facades\Log;

/**
 * 商户自有域名的归属验证与 CF 自定义主机名注册。
 *
 * 为什么必须做归属验证：merchant_domains.host 是全局唯一的，而落地页完全
 * 按 Host 头路由。如果不验证，商户 A 可以把商户 B 的域名填进来占位——
 * 之后所有打到那个域名的流量都会落到 A 的收款链接上，等于劫持。
 */
class MerchantDomainService
{
    public function __construct(
        private readonly CloudflareSaasService $cloudflare,
    ) {}

    /**
     * 执行一次完整的验证流程：DNS 归属验证 → 注册/刷新 CF 自定义主机名。
     *
     * 返回是否"已经完全就绪"（证书也生效了）。返回 false 不代表失败，
     * 更常见的情况是 CF 证书还在签发中，商户过几分钟再点一次即可。
     */
    public function verify(MerchantDomain $domain): bool
    {
        if (! $this->verifyOwnership($domain)) {
            return false;
        }

        try {
            $this->cloudflare->register($domain);
        } catch (\Throwable $e) {
            Log::warning('注册 Cloudflare 自定义主机名失败', [
                'merchant_domain_id' => $domain->id,
                'host' => $domain->host,
                'error' => $e->getMessage(),
            ]);

            $domain->forceFill([
                'cf_last_error' => mb_substr($e->getMessage(), 0, 500),
                'cf_synced_at' => now(),
            ])->save();

            return false;
        }

        return $domain->refresh()->isReady();
    }

    /**
     * DNS 归属验证：查 _sscpay-challenge.{host} 的 TXT 记录里是否包含
     * 我们发给商户的那串 token。
     *
     * 一次验证通过就永久记住（verified_at），不要求商户长期保留 TXT 记录——
     * 但 host 一旦被改动，MerchantDomain 的 updating 钩子会把 verified_at
     * 连同 CF 信息一起清空，强制重新验证。
     */
    public function verifyOwnership(MerchantDomain $domain): bool
    {
        if ($domain->isVerified()) {
            return true;
        }

        $records = $this->lookupTxt($domain->verifyRecordName());

        if ($records === null) {
            $domain->forceFill([
                'last_verify_error' => __('admin.merchant_domain.errors.dns_lookup_failed'),
            ])->save();

            return false;
        }

        foreach ($records as $record) {
            if (str_contains($record, $domain->verify_token)) {
                $domain->forceFill([
                    'verified_at' => now(),
                    'last_verify_error' => null,
                ])->save();

                return true;
            }
        }

        $domain->forceFill([
            'last_verify_error' => __('admin.merchant_domain.errors.txt_not_found'),
        ])->save();

        return false;
    }

    /**
     * 查 TXT 记录。查询本身失败（DNS 超时/域名不存在）返回 null，
     * 与"查到了但没有匹配的记录"（返回空数组）区分开——前者应该提示商户重试，
     * 后者应该提示商户去检查 TXT 记录有没有加对，两种提示不能混为一谈。
     *
     * @return list<string>|null
     */
    private function lookupTxt(string $name): ?array
    {
        // dns_get_record 在解析失败时会发 E_WARNING 并返回 false，
        // 用 @ 抑制掉再按返回值判断，避免警告直接打进日志/响应体。
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return null;
        }

        $values = [];

        foreach ($records as $record) {
            // 长 TXT 记录会被 DNS 切成多段，PHP 放在 entries 里；
            // 只读 txt 字段的话超过 255 字节的记录会被截断导致比对失败。
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', $record['entries']);

                continue;
            }

            if (isset($record['txt'])) {
                $values[] = (string) $record['txt'];
            }
        }

        return $values;
    }
}

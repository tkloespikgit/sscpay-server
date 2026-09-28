<?php

namespace App\Console\Commands;

use App\Models\MerchantDomain;
use App\Services\Checkout\CloudflareSaasService;
use Illuminate\Console\Command;

/**
 * 刷新商户自有域名在 Cloudflare 侧的证书状态。
 *
 * 为什么必须轮询：CF 的证书签发是异步的——我们调 API 注册自定义主机名时拿到的
 * 通常是 pending_validation，要等商户把 DCV 记录加到自己的 DNS 上、CF 校验通过
 * 之后才变成 active。CF 不会回调通知我们，商户也不会盯着后台刷新，只能主动拉。
 *
 * 只处理「已通过 DNS 归属验证、但证书尚未生效」的域名——已经 active 的没必要
 * 反复查（CF API 有速率限制），没验证归属的则还没到注册这一步。
 */
class SyncCheckoutDomains extends Command
{
    protected $signature = 'checkout:sync-domains {--limit=50 : 单次最多处理多少个域名}';

    protected $description = '刷新商户自有域名在 Cloudflare 侧的证书签发状态（收款链接用）';

    public function handle(CloudflareSaasService $cloudflare): int
    {
        if (! $cloudflare->isConfigured()) {
            $this->warn('Cloudflare for SaaS 未配置，跳过。参见 doc/checkout-link-cloudflare-saas.md。');

            return self::SUCCESS;
        }

        $domains = MerchantDomain::query()
            ->withoutGlobalScopes()
            ->whereNotNull('verified_at')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('cf_ssl_status')
                    ->orWhere('cf_ssl_status', '!=', MerchantDomain::CF_STATUS_ACTIVE);
            })
            ->limit((int) $this->option('limit'))
            ->get();

        if ($domains->isEmpty()) {
            $this->info('没有待刷新的域名。');

            return self::SUCCESS;
        }

        $activated = 0;

        foreach ($domains as $domain) {
            try {
                $cloudflare->refresh($domain);
            } catch (\Throwable $e) {
                // 单个域名失败不影响其余的：一个商户的 CF 配置问题不应该
                // 卡住所有其他商户的证书状态更新。
                $this->error("刷新 {$domain->host} 失败：{$e->getMessage()}");

                continue;
            }

            if ($domain->refresh()->isCertificateActive()) {
                $activated++;
                $this->line("<fg=green>✓</> {$domain->host} 证书已生效");
            } else {
                $this->line("  {$domain->host} 状态：".($domain->cf_ssl_status ?: 'unknown'));
            }
        }

        $this->info("处理 {$domains->count()} 个域名，其中 {$activated} 个证书已生效。");

        return self::SUCCESS;
    }
}

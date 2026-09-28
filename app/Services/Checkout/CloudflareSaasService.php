<?php

namespace App\Services\Checkout;

use App\Models\MerchantDomain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cloudflare for SaaS 自定义主机名（Custom Hostnames）。
 *
 * 解决的问题：商户要用自己的域名承载收款链接落地页，而 HTTPS 要求每个域名
 * 都有对应证书。原版 Nginx 的 ssl_certificate 必须在配置文件里写死路径，
 * 没有"握手时现场签发"的能力——意味着每接一个商户都要改生产配置 + 跑 certbot。
 *
 * CF for SaaS 把这件事挪到边缘：商户 CNAME 到我们的 fallback origin，
 * 我们调 API 把域名注册成自定义主机名，CF 负责签发与续期。
 * **源站 Nginx 配置零改动**（只需把 fallback origin 主机名加进现有 server_name），
 * 这也是选它而不是 Caddy on-demand TLS 的原因：后者要 Caddy 接管 443，
 * 改动会波及商户 API 等全部现有服务。
 *
 * 详细对接步骤见 doc/checkout-link-cloudflare-saas.md。
 */
class CloudflareSaasService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function isConfigured(): bool
    {
        return filled(config('services.cloudflare.api_token'))
            && filled(config('services.cloudflare.zone_id'))
            && filled(config('services.cloudflare.fallback_origin'));
    }

    public function fallbackOrigin(): ?string
    {
        return config('services.cloudflare.fallback_origin');
    }

    /**
     * 把域名注册为 CF 自定义主机名，并把 CF 返回的状态与 DCV 记录回写到模型。
     *
     * 幂等：已经有 cf_hostname_id 的直接走刷新，不重复创建——CF 对同一 zone 下
     * 重复的 hostname 会报错，而商户在后台反复点"验证"是很常见的。
     */
    public function register(MerchantDomain $domain): void
    {
        $this->ensureConfigured();

        if (filled($domain->cf_hostname_id)) {
            $this->refresh($domain);

            return;
        }

        $response = $this->client()->post($this->zoneUrl().'/custom_hostnames', [
            'hostname' => $domain->host,
            'ssl' => [
                // txt：DCV 走 TXT 记录，商户加一条就行，不要求先把流量切过来。
                // http 方式要求域名已经指向 CF 才能验证，而商户往往是
                // "先验证拿到证书、确认没问题再切流量"，顺序上会死锁。
                'method' => 'txt',
                'type' => 'dv',
                'settings' => [
                    'min_tls_version' => '1.2',
                ],
            ],
        ]);

        $this->applyResponse($domain, $response->json(), $response->successful());
    }

    /**
     * 拉取 CF 侧的最新证书状态。证书签发是异步的：register() 返回时通常还是
     * pending_validation，要等商户加完 DCV 记录、CF 校验通过才变 active。
     */
    public function refresh(MerchantDomain $domain): void
    {
        $this->ensureConfigured();

        if (blank($domain->cf_hostname_id)) {
            $this->register($domain);

            return;
        }

        $response = $this->client()->get($this->zoneUrl().'/custom_hostnames/'.$domain->cf_hostname_id);

        // 404 说明 CF 侧那条自定义主机名已经不在了（可能被人在 CF 控制台删了）。
        // 清掉本地 ID 让下次重新创建，而不是一直刷一个不存在的资源。
        if ($response->status() === 404) {
            $domain->forceFill([
                'cf_hostname_id' => null,
                'cf_ssl_status' => null,
                'cf_dcv_records' => null,
                'cf_last_error' => 'Custom hostname not found on Cloudflare; it will be re-created on next verification.',
                'cf_synced_at' => now(),
            ])->save();

            return;
        }

        $this->applyResponse($domain, $response->json(), $response->successful());
    }

    /**
     * 删除 CF 侧的自定义主机名。商户在后台删除域名时调用，避免 CF 那边
     * 留下一堆孤儿主机名持续计费。
     *
     * 删除失败不抛异常：本地记录该删还是要删，CF 侧的残留由运维在控制台清理，
     * 不能因为外部 API 故障就阻塞商户的正常操作。
     */
    public function delete(MerchantDomain $domain): void
    {
        if (! $this->isConfigured() || blank($domain->cf_hostname_id)) {
            return;
        }

        try {
            $this->client()->delete($this->zoneUrl().'/custom_hostnames/'.$domain->cf_hostname_id);
        } catch (\Throwable $e) {
            Log::warning('删除 Cloudflare 自定义主机名失败，需要在 CF 控制台手工清理', [
                'merchant_domain_id' => $domain->id,
                'host' => $domain->host,
                'cf_hostname_id' => $domain->cf_hostname_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 改名时使用：确认旧主机名已从 CF 删除，才允许清空本地 ID。
     * 删除失败必须抛出异常，否则旧记录会成为无法追踪的孤儿。
     */
    public function deleteOrFail(MerchantDomain $domain): void
    {
        $this->ensureConfigured();

        if (blank($domain->cf_hostname_id)) {
            return;
        }

        $response = $this->client()->delete($this->zoneUrl().'/custom_hostnames/'.$domain->cf_hostname_id);

        // CF 侧已不存在也视为完成清理。
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Cloudflare custom hostname deletion failed: '.$this->extractError($response->json()));
        }
    }

    /**
     * 把 CF 的响应落到模型上。CF 的响应结构：
     *   result.id                     自定义主机名 ID
     *   result.ssl.status             证书状态（pending_validation / active / ...）
     *   result.ssl.validation_errors  校验失败原因
     *   result.ssl.txt_name / txt_value  商户需要添加的 DCV TXT 记录
     */
    private function applyResponse(MerchantDomain $domain, ?array $payload, bool $successful): void
    {
        if (! $successful || ($payload['success'] ?? true) === false) {
            $domain->forceFill([
                'cf_last_error' => $this->extractError($payload),
                'cf_synced_at' => now(),
            ])->save();

            return;
        }

        $result = $payload['result'] ?? [];
        $ssl = $result['ssl'] ?? [];

        $domain->forceFill([
            'cf_hostname_id' => $result['id'] ?? $domain->cf_hostname_id,
            'cf_ssl_status' => $ssl['status'] ?? null,
            'cf_dcv_records' => $this->extractDcvRecords($ssl),
            'cf_last_error' => $this->extractSslError($ssl),
            'cf_synced_at' => now(),
        ])->save();
    }

    /**
     * 提取商户需要自行添加的 DCV 校验记录。CF 在不同阶段返回的结构不一样
     * （顶层 txt_name/txt_value，或 validation_records 数组），两种都兜住。
     */
    private function extractDcvRecords(array $ssl): ?array
    {
        $records = [];

        // 同时处理顶层旧格式与完整数组，不能找到第一条就丢弃其余证书的验证记录。
        foreach ([$ssl, ...($ssl['validation_records'] ?? []), ...($ssl['dcv_delegation_records'] ?? [])] as $record) {
            $txtValue = $record['txt_value'] ?? $record['txt_record'] ?? null;

            if (filled($record['txt_name'] ?? null) && filled($txtValue)) {
                $records[] = [
                    'type' => 'TXT',
                    'name' => $record['txt_name'],
                    'value' => $txtValue,
                ];
            }

            if (filled($record['cname'] ?? null) && filled($record['cname_target'] ?? null)) {
                $records[] = [
                    'type' => 'CNAME',
                    'name' => $record['cname'],
                    'value' => $record['cname_target'],
                ];
            }
        }

        // 注册时选择 TXT 验证；委派 CNAME 是另一种方式，不能提示用户在同名 DNS
        // 节点同时添加 TXT 和 CNAME。存在 TXT 时优先展示全部 TXT。
        $txtRecords = array_filter($records, fn (array $record) => $record['type'] === 'TXT');
        $records = $txtRecords ?: $records;

        return $records ? array_values(array_unique($records, SORT_REGULAR)) : null;
    }

    private function extractSslError(array $ssl): ?string
    {
        $errors = [];

        foreach ($ssl['validation_errors'] ?? [] as $error) {
            if (filled($error['message'] ?? null)) {
                $errors[] = $error['message'];
            }
        }

        return $errors ? mb_substr(implode('; ', $errors), 0, 500) : null;
    }

    private function extractError(?array $payload): string
    {
        $messages = [];

        foreach ($payload['errors'] ?? [] as $error) {
            $messages[] = trim(($error['code'] ?? '').' '.($error['message'] ?? ''));
        }

        return mb_substr($messages ? implode('; ', $messages) : 'Cloudflare API request failed.', 0, 500);
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.cloudflare.api_token'))
            ->acceptJson()
            ->timeout(15);
    }

    private function zoneUrl(): string
    {
        return self::API_BASE.'/zones/'.config('services.cloudflare.zone_id');
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Cloudflare for SaaS is not configured. Set CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID and CLOUDFLARE_FALLBACK_ORIGIN.'
            );
        }
    }
}

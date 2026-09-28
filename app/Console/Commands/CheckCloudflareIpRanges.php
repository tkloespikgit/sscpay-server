<?php

namespace App\Console\Commands;

use App\Support\CloudflareIpRanges;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 核对 App\Support\CloudflareIpRanges 里写死的网段是否还和 Cloudflare
 * 官方发布的一致。
 *
 * 为什么需要这个命令：那份列表是 TrustProxies 的可信代理来源，直接决定
 * $request->ip() 拿到的是真实客户 IP 还是 CF 边缘 IP。CF 新增网段而我们没跟上，
 * 结果是从新网段进来的客户全部被记成 CF 的 IP —— 订单里的 customer_ip 失真、
 * 同 IP 频率限制被击穿，而且**不会报任何错**，只会静默地错下去。
 *
 * 建议挂进调度每周跑一次：
 *   $schedule->command('checkout:check-cloudflare-ips')->weeklyOn(1, '9:00');
 * 有差异时返回退出码 1，方便被监控捕捉到。
 */
class CheckCloudflareIpRanges extends Command
{
    protected $signature = 'checkout:check-cloudflare-ips';

    protected $description = '核对本地写死的 Cloudflare 网段与官方发布列表是否一致（收款链接的可信代理来源）';

    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/ips';

    public function handle(): int
    {
        try {
            $response = Http::acceptJson()->timeout(20)->get(self::ENDPOINT);
        } catch (\Throwable $e) {
            $this->error('拉取 Cloudflare 网段列表失败：'.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $this->error('Cloudflare 接口返回异常，HTTP '.$response->status());

            return self::FAILURE;
        }

        $remote = array_merge(
            $response->json('result.ipv4_cidrs', []),
            $response->json('result.ipv6_cidrs', []),
        );

        $local = CloudflareIpRanges::all();

        $missing = array_values(array_diff($remote, $local));   // CF 有、我们没有
        $obsolete = array_values(array_diff($local, $remote));  // 我们有、CF 已移除

        if (! $missing && ! $obsolete) {
            $this->info('一致，共 '.count($local).' 个网段，无需改动。');

            return self::SUCCESS;
        }

        $this->warn('Cloudflare 网段有变化，请更新 app/Support/CloudflareIpRanges.php：');

        if ($missing) {
            $this->newLine();
            $this->line('<fg=green>需要新增</>（漏掉会导致这些网段来的客户 IP 全部失真）：');
            foreach ($missing as $cidr) {
                $this->line('  + '.$cidr);
            }
        }

        if ($obsolete) {
            $this->newLine();
            $this->line('<fg=yellow>已被 CF 移除</>（留着的风险：该网段若被转售给他人，对方可伪造客户 IP）：');
            foreach ($obsolete as $cidr) {
                $this->line('  - '.$cidr);
            }
        }

        return self::FAILURE;
    }
}

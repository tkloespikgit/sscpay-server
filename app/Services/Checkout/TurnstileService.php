<?php

namespace App\Services\Checkout;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile 人机验证。收款链接落地页和后台登录页共用 Siteverify。
 *
 * 未配置密钥时 verify() 直接返回 true（本地开发/测试不强制接入），
 * 这一点在生产环境要特别注意：上线前必须把两把密钥配上，
 * 否则等于没开验证。isConfigured() 供后台做上线前自检。
 */
class TurnstileService
{
    private const VERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function isConfigured(): bool
    {
        return filled(config('services.turnstile.secret_key'))
            && filled(config('services.turnstile.site_key'));
    }

    public function siteKey(): ?string
    {
        return config('services.turnstile.site_key');
    }

    /**
     * @param  string|null  $token  前端 Turnstile 组件提交上来的 cf-turnstile-response
     * @param  string|null  $ip  客户端 IP，传给 CF 做额外风险判断（可选）
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::VERIFY_ENDPOINT, array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $e) {
            // CF 挂了或网络不通时**拒绝**放行，而不是放行。
            // 反过来做的话，攻击者只要让这个请求超时就能绕过整个验证。
            Log::warning('Turnstile 校验请求失败，本次提交按未通过处理', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Turnstile 校验接口返回非 2xx', [
                'status' => $response->status(),
            ]);

            return false;
        }

        $success = (bool) $response->json('success', false);

        if (! $success) {
            Log::info('Turnstile 校验未通过', [
                'error_codes' => $response->json('error-codes', []),
            ]);
        }

        return $success;
    }
}

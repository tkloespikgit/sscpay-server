<?php

namespace App\Services;

use App\Models\SiteProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class ProductNameTranslationService
{
    /** 百度智能云 OAuth2 token 获取地址（API Key + Secret Key 换 access_token）。 */
    private const BAIDU_TOKEN_URL = 'https://aip.baidubce.com/oauth/2.0/token';

    /** 百度智能云机器翻译接口，单次请求只支持一条文本。 */
    private const BAIDU_TEXTTRANS_URL = 'https://aip.baidubce.com/rpc/2.0/mt/texttrans/v1';

    private const BAIDU_TOKEN_CACHE_KEY = 'baidu_translate_access_token';

    public function translateMissing(SiteProduct $product): void
    {
        if (filled($product->name_translated) || blank($product->name)) {
            return;
        }

        $apiKey = config('services.baidu_translate.api_key');
        $secretKey = config('services.baidu_translate.secret_key');

        if (blank($apiKey) || blank($secretKey)) {
            return;
        }

        $translation = $this->translateOne($product->name, $apiKey, $secretKey);
        Sleep::for(1)->second();

        if (blank($translation)) {
            return;
        }

        SiteProduct::query()
            ->whereKey($product->getKey())
            ->where('payment_method_id', $product->payment_method_id)
            ->where('name', $product->name)
            ->where('name_translated', $product->name_translated)
            ->update(['name_translated' => mb_substr($translation, 0, 500)]);

        $product->refresh();
    }

    /**
     * 翻译单条文本；遇到 token 失效类错误会强制刷新 token 重试一次。
     */
    private function translateOne(string $text, string $apiKey, string $secretKey): ?string
    {
        $token = $this->baiduAccessToken($apiKey, $secretKey);

        if ($token === null) {
            return null;
        }

        try {
            $body = $this->postTexttrans($token, $text);

            // 14/110/111：IAM 认证失败 / token 无效 / token 过期，刷新后重试一次。
            if (in_array((string) ($body['error_code'] ?? ''), ['14', '110', '111'], true)) {
                $token = $this->baiduAccessToken($apiKey, $secretKey, refresh: true);

                if ($token === null) {
                    return null;
                }

                $body = $this->postTexttrans($token, $text);
            }

            $dst = $body['result']['trans_result'][0]['dst'] ?? null;

            if (is_string($dst) && $dst !== '') {
                return $dst;
            }

            Log::warning('Baidu texttrans request failed', [
                'error_code' => $body['error_code'] ?? null,
                'error_msg' => $body['error_msg'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Baidu texttrans request error', ['exception' => $e::class]);
        }

        return null;
    }

    private function postTexttrans(string $token, string $text): array
    {
        $response = Http::connectTimeout(5)->timeout(30)->post(self::BAIDU_TEXTTRANS_URL.'?access_token='.$token, [
            'q' => $text,
            'from' => 'auto',
            'to' => 'zh',
        ]);

        return $response->json() ?? [];
    }

    /**
     * 用 API Key / Secret Key 换取 access_token，有效期约 30 天，
     * 结果进缓存复用；$refresh = true 时强制重新获取。
     */
    private function baiduAccessToken(string $apiKey, string $secretKey, bool $refresh = false): ?string
    {
        if (! $refresh) {
            $cached = Cache::get(self::BAIDU_TOKEN_CACHE_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->get(self::BAIDU_TOKEN_URL, [
                'grant_type' => 'client_credentials',
                'client_id' => $apiKey,
                'client_secret' => $secretKey,
            ]);

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                Log::warning('Baidu access token request failed', [
                    'error' => $response->json('error_description') ?? $response->json('error'),
                ]);

                return null;
            }

            // 提前一天刷新，避免临界过期。
            $expiresIn = (int) ($response->json('expires_in') ?? 2592000);
            Cache::put(self::BAIDU_TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 86400));

            return $token;
        } catch (\Throwable $e) {
            Log::warning('Baidu access token request error', ['exception' => $e::class]);

            return null;
        }
    }
}

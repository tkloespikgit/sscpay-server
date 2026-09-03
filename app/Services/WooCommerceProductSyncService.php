<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\SiteProduct;
use App\Models\SiteProductVariation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 站点商品同步服务。
 *
 * 通过支付方式上配置的 WordPress 站点凭证（domain + WooCommerce REST API
 * Consumer Key / Secret）调用插件扩展的 products-with-variations 端点，
 * 一次性拉取全部已发布商品及其变体数据，连同名称中文翻译（百度智能云
 * 机器翻译 texttrans 接口）一起 upsert 到本地 site_products 表，变体单独
 * 写入 site_product_variations 表（简单商品没有变体，主商品自身作为唯一
 * 变体写入）；远端已删除的商品会连同变体一起清理。
 * 金额币种当前站点全部为美金（USD）。整个同步是幂等的，失败可由队列重试。
 */
class WooCommerceProductSyncService
{
    /** 当前同步的站点金额币种统一为美金。 */
    private const CURRENCY = 'USD';

    /**
     * WooCommerce REST API 分页大小（官方上限 100）。
     * 目标站点多为低速共享主机，单次返回 100 条要 16 秒以上，
     * 取小分页缩短单次请求时长，降低跨境链路超时概率。
     */
    private const PER_PAGE = 20;

    /** 安全上限：最多翻页 200 页（2 万商品），防止异常响应导致死循环。 */
    private const MAX_PAGES = 200;

    /** 单次 WooCommerce 请求的超时秒数（目标站点响应普遍偏慢）。 */
    private const HTTP_TIMEOUT = 60;

    /** 网络层异常（超时/连接失败）的就地重试次数，避免偶发抖动直接拖垮整个任务。 */
    private const REQUEST_RETRIES = 3;

    /** 百度智能云 OAuth2 token 获取地址（API Key + Secret Key 换 access_token）。 */
    private const BAIDU_TOKEN_URL = 'https://aip.baidubce.com/oauth/2.0/token';

    /** 百度智能云机器翻译接口，单次请求只支持一条文本。 */
    private const BAIDU_TEXTTRANS_URL = 'https://aip.baidubce.com/rpc/2.0/mt/texttrans/v1';

    private const BAIDU_TOKEN_CACHE_KEY = 'baidu_translate_access_token';

    /**
     * @return array{total: int, created: int, updated: int, deleted: int}
     */
    public function sync(PaymentMethod $paymentMethod): array
    {
        Log::info('start to sync remote products', ['payment_method_id' => $paymentMethod->id]);
        if (blank($paymentMethod->domain) || blank($paymentMethod->domain_client_id) || blank($paymentMethod->domain_client_sk)) {
            throw new RuntimeException('站点配置不完整：缺少网站域名或 WooCommerce REST API 密钥');
        }

        $http = Http::withBasicAuth($paymentMethod->domain_client_id, $paymentMethod->domain_client_sk)
            ->withoutVerifying()
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT);


        $base = rtrim($paymentMethod->domain, '/').'/wp-json/wc/v3';

        $products = $this->fetchAllProducts($http, $base);

        // 名称先解码 HTML 实体并清洗，再批量走百度翻译（失败不阻断同步）。
        $names = array_map(fn (array $p) => $this->cleanName($p['name'] ?? ''), $products);
        $translations = $this->translateNames($names);

        $now = now();
        $syncedIds = [];
        $created = 0;
        $updated = 0;

        foreach ($products as $index => $product) {
            // 商品与其变体在同一事务内写入，保证两边数据一致。
            $row = DB::transaction(function () use ($paymentMethod, $product, $names, $translations, $index, $now) {
                $row = SiteProduct::query()->updateOrCreate(
                    [
                        'payment_method_id' => $paymentMethod->id,
                        'woo_product_id' => $product['woo_product_id'],
                    ],
                    [
                        'merchant_id' => $paymentMethod->merchant_id,
                        'product_type' => $product['product_type'],
                        'name' => mb_substr($names[$index], 0, 500),
                        'name_translated' => $translations[$index] !== null
                            ? mb_substr($translations[$index], 0, 500)
                            : null,
                        'sku' => $product['sku'],
                        'price_min' => $product['price_min'],
                        'price_max' => $product['price_max'],
                        'currency' => self::CURRENCY,
                        'image_url' => $product['image_url'],
                        'permalink' => $product['permalink'],
                        'synced_at' => $now,
                    ]
                );

                $this->syncVariations($row, $product['variations'] ?? []);

                return $row;
            });

            $row->wasRecentlyCreated ? $created++ : $updated++;
            $syncedIds[] = $product['woo_product_id'];
        }

        // 远端拉回空列表时不执行清理：可能是站点异常，避免误删全量数据。
        $deleted = 0;
        if ($syncedIds !== []) {
            $deleted = SiteProduct::query()
                ->where('payment_method_id', $paymentMethod->id)
                ->whereNotIn('woo_product_id', $syncedIds)
                ->delete();
        } else {
            Log::warning('WooCommerce sync fetched no products, skip cleanup', [
                'payment_method_id' => $paymentMethod->id,
                'domain' => $paymentMethod->domain,
            ]);
        }

        return [
            'total' => count($syncedIds),
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    /**
     * 分页拉取全部已发布商品；插件端点已在响应内联返回变体列表，
     * 无需再按商品逐个请求变体。
     */
    private function fetchAllProducts(PendingRequest $http, string $base): array
    {
        $products = [];
        $page = 1;

        do {
            $response = $this->get($http, $base.'/products-with-variations', [
                'per_page' => self::PER_PAGE,
                'page' => $page,
                'force_refresh' => true
            ]);

            $items = $response->json() ?? [];

            foreach ($items as $item) {
                $products[] = $this->mapProduct($item);
            }

            $page++;

            // 插件自定义端点不返回 X-WP-TotalPages 分页头（实测为空），
            // 只能当辅助终止条件；缺失时持续翻页直到拿到空页为止，
            // MAX_PAGES 作为安全上限防止异常响应导致死循环。
            $totalPages = (int) $response->header('X-WP-TotalPages');
        } while ($items !== []
            && $page <= self::MAX_PAGES
            && ($totalPages === 0 || $page <= $totalPages));

        return $products;
    }

    private function mapProduct(array $item): array
    {
        $variations = $this->mapVariations($item['variations'] ?? []);
        $isVariable = $variations !== [];

        if ($isVariable) {
            // 变体商品的销售价格范围取所有变体的实际售价。
            $prices = array_map(fn (array $v) => $v['price'], $variations);
            $priceMin = min($prices);
            $priceMax = max($prices);
        } else {
            // 无变体（variations 为空）视为简单商品：price 是插件给出的当前售价，
            // 缺失时回退原价；单值商品上下限相同。
            $price = (float) (($item['price'] ?? '') !== '' ? $item['price'] : ($item['regular_price'] ?? 0));
            $priceMin = $priceMax = $price;

            // 简单商品没有变体，把主商品自身作为唯一一条变体写入，
            // 变体 ID 直接用主商品 ID（同一商品下依然唯一）。
            $variations = [[
                'id' => (int) $item['id'],
                'sku' => ($item['sku'] ?? '') !== '' ? $item['sku'] : null,
                'price' => $price,
            ]];
        }

        return [
            'woo_product_id' => (int) $item['id'],
            'product_type' => $isVariable ? 'variable' : 'simple',
            'name' => $item['name'] ?? '',
            'sku' => ($item['sku'] ?? '') !== '' ? $item['sku'] : null,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'variations' => $variations,
            'image_url' => ($item['image'] ?? '') !== '' ? $item['image'] : null,
            'permalink' => $item['permalink'] ?? null,
        ];
    }

    /**
     * 归一化插件内联返回的变体列表，保存 ID / SKU / 实际售价。
     *
     * @return list<array{id: int, sku: string|null, price: float}>
     */
    private function mapVariations(array $variations): array
    {
        return array_map(
            fn (array $variation) => [
                'id' => (int) $variation['id'],
                'sku' => ($variation['sku'] ?? '') !== '' ? $variation['sku'] : null,
                'price' => (float) (($variation['price'] ?? '') !== '' ? $variation['price'] : ($variation['regular_price'] ?? 0)),
            ],
            array_values($variations)
        );
    }

    /**
     * 把变体写入独立的 site_product_variations 表：远端存在的变体 upsert，
     * 远端已删除的变体清理掉；简单商品的主商品自身已在 mapProduct 中
     * 构造成唯一变体，同样走这里的 upsert。
     *
     * @param  list<array{id: int, sku: string|null, price: float}>  $variations
     */
    private function syncVariations(SiteProduct $product, array $variations): void
    {
        $syncedIds = [];

        foreach ($variations as $variation) {
            SiteProductVariation::query()->updateOrCreate(
                [
                    'site_product_id' => $product->id,
                    'woo_variation_id' => $variation['id'],
                ],
                [
                    'sku' => $variation['sku'],
                    'price' => $variation['price'],
                    'currency' => self::CURRENCY,
                ]
            );

            $syncedIds[] = $variation['id'];
        }

        $query = SiteProductVariation::query()->where('site_product_id', $product->id);

        if ($syncedIds !== []) {
            $query->whereNotIn('woo_variation_id', $syncedIds);
        }

        $query->delete();
    }

    /**
     * 发起 GET 请求；网络层异常（cURL 28 超时、连接失败等）先就地重试几次，
     * 都失败才抛出，避免偶发抖动直接让整个同步任务失败重来。
     */
    private function get(PendingRequest $http, string $url, array $query): Response
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $http->get($url, $query);
            } catch (ConnectionException $e) {
                $attempt++;

                if ($attempt >= self::REQUEST_RETRIES) {
                    throw new RuntimeException(
                        "连接站点失败，已重试 {$attempt} 次：{$e->getMessage()}", 0, $e
                    );
                }

                Log::warning('WooCommerce API request failed, retrying', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                sleep(3);

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'WooCommerce API 请求失败（%d）：%s %s',
                    $response->status(),
                    $url,
                    mb_substr($response->body(), 0, 200)
                ));
            }

            return $response;
        }
    }

    /**
     * 百度智能云机器翻译（BCE texttrans）：API Key + Secret Key 换取的
     * access_token 鉴权，JSON 请求，单次只能传一条文本，逐条翻译。
     * 未配置凭证时跳过翻译。
     *
     * @param  list<string>  $names
     * @return list<string|null> 与入参顺序一一对应，失败或未配置时为 null
     */
    private function translateNames(array $names): array
    {
        $apiKey = config('services.baidu_translate.api_key');
        $secretKey = config('services.baidu_translate.secret_key');

        if (blank($apiKey) || blank($secretKey)) {
            Log::info('Baidu translate credentials missing, skip product name translation');

            return array_fill(0, count($names), null);
        }

        $result = array_fill(0, count($names), null);

        foreach ($names as $index => $name) {
            if ($name === '') {
                continue;
            }

            $result[$index] = $this->translateOne($name, $apiKey, $secretKey);

            // 控制调用频率，避免触发免费资源包的 QPS 限流。
            sleep(1);
        }

        return $result;
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
            Log::warning('Baidu texttrans request error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function postTexttrans(string $token, string $text): array
    {
        $response = Http::timeout(30)->post(self::BAIDU_TEXTTRANS_URL.'?access_token='.$token, [
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
            $response = Http::timeout(15)->get(self::BAIDU_TOKEN_URL, [
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
            Log::warning('Baidu access token request error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function cleanName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/[\r\n\t]+/u', ' ', $name) ?? $name;

        return trim($name);
    }
}

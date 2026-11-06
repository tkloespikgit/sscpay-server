<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\SiteProduct;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\WooCommerceProductSyncService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ProductNameTranslationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.baidu_translate.api_key' => 'test-key', 'services.baidu_translate.secret_key' => 'test-secret']);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_sync_never_translates_and_preserves_existing_translations_until_the_source_name_changes(): void
    {
        $method = $this->method();
        $existing = $this->product($method, 1, ['name' => 'Same name', 'name_translated' => '已有译名']);
        $renamed = $this->product($method, 2, ['name' => 'Old name', 'name_translated' => '旧译名']);
        Http::fake(['https://shop.example.com/*' => Http::response([
            ['id' => 1, 'name' => 'Same name', 'price' => '12.00'],
            ['id' => 2, 'name' => 'New name', 'price' => '13.00'],
            ['id' => 3, 'name' => 'New &amp; Fresh', 'price' => '14.00'],
        ], headers: ['X-WP-TotalPages' => '1'])]);

        $stats = app(WooCommerceProductSyncService::class)->sync($method);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(2, $stats['updated']);
        $this->assertSame('已有译名', $existing->fresh()->name_translated);
        $this->assertSame('New name', $renamed->fresh()->name);
        $this->assertNull($renamed->fresh()->name_translated);
        $this->assertDatabaseHas('site_products', ['payment_method_id' => $method->id, 'woo_product_id' => 3, 'name' => 'New & Fresh', 'name_translated' => null]);
        $this->assertDatabaseCount('site_product_variations', 3);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_generation_translates_only_selected_missing_names_and_reuses_saved_translations(): void
    {
        $method = $this->method();
        $method->update(['merchant_id' => null]);
        $existing = $this->product($method, 1, ['name_translated' => '已有中文']);
        $missing = $this->product($method, 2);
        $otherMethod = $this->makePaymentMethod('other-site');
        $other = $this->product($otherMethod, 3);
        $initialTransactionLevel = DB::transactionLevel();
        Http::fake([
            '*oauth/2.0/token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 2592000]),
            '*texttrans*' => function (Request $request) use ($initialTransactionLevel) {
                $this->assertSame($initialTransactionLevel, DB::transactionLevel());
                $this->assertSame('Product 2', $request['q']);
                $this->assertSame('zh', $request['to']);

                return Http::response(['result' => ['trans_result' => [['dst' => '新译名']]]]);
            },
        ]);
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        $order = app(PurchaseOrderService::class)->generate($method, $admin, $this->orderData());
        $second = app(PurchaseOrderService::class)->generate($method, $admin, $this->orderData());

        $this->assertSame('新译名', $missing->fresh()->name_translated);
        $this->assertSame('已有中文', $existing->fresh()->name_translated);
        $this->assertNull($other->fresh()->name_translated);
        foreach ([$order, $second] as $purchaseOrder) {
            $this->assertEqualsCanonicalizing(['已有中文', '新译名'], $purchaseOrder->items->pluck('product_name')->all());
        }
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_generation_does_not_translate_products_outside_the_random_selection(): void
    {
        $method = $this->method();
        foreach (range(1, 40) as $id) {
            $this->product($method, $id);
        }
        $translatedNames = [];
        Http::fake([
            '*oauth/2.0/token*' => Http::response(['access_token' => 'test-token']),
            '*texttrans*' => function (Request $request) use (&$translatedNames) {
                $translatedNames[] = $request['q'];

                return Http::response(['result' => ['trans_result' => [['dst' => '译名'.$request['q']]]]]);
            },
        ]);

        $order = app(PurchaseOrderService::class)->generate($method, User::factory()->create(['is_super_admin' => true, 'status' => true]), $this->orderData());

        $selectedNames = SiteProduct::whereIn('id', $order->items->pluck('site_product_id'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing($selectedNames, $translatedNames);
        $this->assertSame(0, SiteProduct::whereNotIn('id', $order->items->pluck('site_product_id'))->whereNotNull('name_translated')->count());
        Http::assertSentCount(count($selectedNames) + 1);
        Sleep::assertSleptTimes(count($selectedNames));
    }

    #[TestWith(['missing_credentials'])]
    #[TestWith(['connection_failure'])]
    #[TestWith(['api_error'])]
    public function test_unavailable_translation_keeps_the_original_name_and_can_be_retried(string $failure): void
    {
        $method = $this->method();
        $product = $this->product($method, 1);
        $recovered = false;
        Http::fake([
            '*oauth/2.0/token*' => Http::response(['access_token' => 'test-token']),
            '*texttrans*' => function (Request $request) use (&$recovered, $failure) {
                if ($recovered) {
                    return Http::response(['result' => ['trans_result' => [['dst' => '重试译名']]]]);
                }
                if ($failure === 'connection_failure') {
                    return (Http::failedConnection('Test connection failure'))($request);
                }

                return Http::response(['error_code' => '54003']);
            },
        ]);
        if ($failure === 'missing_credentials') {
            config(['services.baidu_translate.api_key' => null]);
        }
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        $order = app(PurchaseOrderService::class)->generate($method, $admin, $this->orderData());

        $this->assertSame('Product 1', $order->items->sole()->product_name);
        $this->assertNull($product->fresh()->name_translated);
        if ($failure === 'missing_credentials') {
            Http::assertNothingSent();
        } else {
            Http::assertSentCount(2);
        }
        config(['services.baidu_translate.api_key' => 'test-key']);
        $recovered = true;
        $retried = app(PurchaseOrderService::class)->generate($method, $admin, $this->orderData());
        $this->assertSame('重试译名', $retried->items->sole()->product_name);
        $this->assertSame('Product 1', $order->fresh('items')->items->sole()->product_name);
    }

    public function test_generation_refreshes_an_expired_translation_token_once(): void
    {
        $method = $this->method();
        $product = $this->product($method, 1);
        Cache::put('baidu_translate_access_token', 'expired-token', 60);
        Http::fake([
            '*oauth/2.0/token*' => Http::response(['access_token' => 'fresh-token']),
            '*texttrans*' => Http::sequence()
                ->push(['error_code' => '110'])
                ->push(['result' => ['trans_result' => [['dst' => '刷新后译名']]]]),
        ]);

        $order = app(PurchaseOrderService::class)->generate($method, User::factory()->create(['is_super_admin' => true, 'status' => true]), $this->orderData());

        $this->assertSame('刷新后译名', $product->fresh()->name_translated);
        $this->assertSame('刷新后译名', $order->items->sole()->product_name);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'texttrans') && str_contains($request->url(), 'access_token=fresh-token'));
    }

    public function test_unauthorized_generation_does_not_call_translation(): void
    {
        $method = $this->method();
        $this->product($method, 1);
        Http::fake();
        $operator = User::factory()->create(['is_super_admin' => false, 'status' => true]);

        try {
            app(PurchaseOrderService::class)->generate($method, $operator, $this->orderData());
            $this->fail('Generation should be denied.');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('purchase_orders', 0);
        }
    }

    private function method(): PaymentMethod
    {
        $this->createMerchantAndApplication();

        return $this->makePaymentMethod('translation-site');
    }

    /** @param array<string, mixed> $overrides */
    private function product(PaymentMethod $method, int $id, array $overrides = []): SiteProduct
    {
        return $method->siteProducts()->create([
            'merchant_id' => $method->merchant_id,
            'woo_product_id' => $id,
            'name' => 'Product '.$id,
            'price_min' => '1.00',
            'price_max' => '1.00',
            'currency' => 'USD',
            ...$overrides,
        ]);
    }

    /** @return array{ordered_at: string} */
    private function orderData(): array
    {
        return ['ordered_at' => '2026-10-01 12:00:00'];
    }
}

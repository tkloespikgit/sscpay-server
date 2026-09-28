<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CheckoutLink;
use App\Models\Merchant;
use App\Models\MerchantDomain;
use App\Models\Order;
use App\Models\PaymentGroup;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodConfigMap;
use App\Models\SiteProduct;
use App\Models\SiteProductVariation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 收款链接落地页的端到端测试。
 *
 * 覆盖重点是"公开写入口"这个身份带来的风险面：访问控制（slug + Host）、
 * 金额不可被客户篡改、防刷各层是否真的拦得住，以及建单数据是否正确落到
 * orders 表上。支付网关的 /pay 一律 Http::fake，不打真实网络。
 */
class CheckoutLinkTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Application $application;

    private PaymentGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $siteDomain = 'https://shop.example.com';

        $this->merchant = Merchant::create([
            'name' => 'TV Box Store',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);

        $this->application = Application::createWithCredentials([
            'merchant_id' => $this->merchant->id,
            'name' => 'Checkout App',
            'website' => $siteDomain,
        ]);

        $this->group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'group_default',
            'group_name' => 'Default Group',
            'is_active' => true,
        ]);

        $configMap = PaymentMethodConfigMap::create([
            'name' => 'Test Gateway',
            'payment_config_tag' => 'stripe',
            'fields' => [],
        ]);

        $method = PaymentMethod::create([
            'merchant_id' => $this->merchant->id,
            'method_code' => 'test_method',
            'method_name' => 'Test Method',
            'is_active' => true,
            'config_map_id' => $configMap->id,
            'domain' => $siteDomain,
            'domain_client_id' => 'ck_test',
            'domain_client_sk' => 'cs_test',
            'product_match_mode' => PaymentMethod::MODE_MATCH,
        ]);

        $this->group->paymentMethods()->attach($method->id, ['priority' => 100]);

        // MATCH 模式下，同步给电商站点的明细是从站点商品里凑出来的，不是链接上
        // 配的商品（见 create_checkout_link_items_table 的注释）。这里备一批变体
        // 供凑单算法使用——总容量（单价 × 单品件数上限之和）必须大于测试里用到的
        // 最大订单金额，否则 OrderItemService 会以"站点商品容量不足"直接抛异常。
        $product = SiteProduct::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $method->id,
            'woo_product_id' => 1,
            'product_type' => 'simple',
            'name' => 'Filler Product',
            'sku' => 'FILLER-1',
            'price_min' => '5.00',
            'price_max' => '50.00',
            'currency' => 'USD',
            'permalink' => $siteDomain.'/product/filler',
        ]);

        foreach ([5, 20, 50] as $index => $price) {
            SiteProductVariation::create([
                'site_product_id' => $product->id,
                'woo_variation_id' => 100 + $index,
                'sku' => 'FILLER-V'.$index,
                'price' => number_format($price, 2, '.', ''),
                'currency' => 'USD',
            ]);
        }

        Http::fake([
            '*/wp-json/payment-plugin/v1/pay*' => Http::response([
                'code' => 0,
                'data' => ['pay_url' => 'https://gateway.example.com/pay/abc', 'wp_order_id' => 99],
            ], 200),
        ]);
    }

    public function test_landing_page_renders_title_and_items(): void
    {
        $link = $this->createLink();
        $link->items()->create([
            'product_name' => 'TV Box model 209343',
            'unit_price' => '80.00',
            'quantity' => 1,
            'sort_order' => 0,
        ]);
        $link->update(['fixed_amount' => '80.00']);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('TV box - model 209343 checkout')
            ->assertSee('Order Summary')
            ->assertSee('TV Box model 209343');
    }

    public function test_range_link_hides_order_summary_and_displays_escaped_customer_notice(): void
    {
        $link = $this->createLink([
            'amount_mode' => CheckoutLink::MODE_RANGE,
            'fixed_amount' => null,
            'min_amount' => '10.00',
            'max_amount' => '50.00',
            'customer_notice' => "Please review your details.\n<script>alert('x')</script>",
        ]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('checkout-shell--no-summary')
            ->assertDontSee('Order Summary')
            ->assertSee('Please review your details.')
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false)
            ->assertDontSee("<script>alert('x')</script>", false);
    }

    public function test_link_without_own_google_key_uses_location_selectors(): void
    {
        config()->set('services.google_maps.browser_key', 'system-key-must-not-be-used');
        $link = $this->createLink();

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('state-select')
            ->assertSee('city-select')
            ->assertSee('id="state-search" type="search" autocomplete="off" hidden', false)
            ->assertSee('id="city-search" type="search" autocomplete="off" hidden', false)
            ->assertSee('id="state-options" role="listbox" hidden', false)
            ->assertSee('id="city-options" role="listbox" hidden', false)
            ->assertDontSee('maps.googleapis.com')
            ->assertDontSee('system-key-must-not-be-used');
    }

    public function test_link_with_own_google_key_uses_google_address_suggestions(): void
    {
        $link = $this->createLink(['google_maps_browser_key' => 'merchant-test-key']);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('key=merchant-test-key')
            ->assertDontSee('state-select');
    }

    public function test_configured_countries_limit_the_checkout_options_and_submissions(): void
    {
        $link = $this->createLink(['supported_countries' => ['DE', 'FR']]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('data-allowed-countries=', false)
            ->assertSee('<option value="DE"', false)
            ->assertSee('<option value="FR"', false)
            ->assertDontSee('<option value="US"', false);

        $this->post('/c/'.$link->slug, $this->validPayload(['country' => 'US']))
            ->assertSessionHasErrors('country');
        $this->post('/c/'.$link->slug, $this->validPayload(['phone' => '+14155552671']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        $this->post('/c/'.$link->slug, $this->validPayload())
            ->assertRedirect('https://gateway.example.com/pay/abc');
    }

    public function test_only_trusted_cloudflare_requests_can_recommend_a_country(): void
    {
        $link = $this->createLink(['supported_countries' => ['DE', 'FR']]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('CF-IPCountry', 'FR')
            ->get('/c/'.$link->slug)
            ->assertDontSee('<option value="FR" selected', false);

        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.1'])
            ->withHeader('CF-IPCountry', 'FR')
            ->get('/c/'.$link->slug)
            ->assertSee('<option value="FR" selected', false);
    }

    public function test_location_selectors_load_states_then_cities(): void
    {
        Http::fake([
            'countriesnow.space/*/states/q*' => Http::response([
                'error' => false,
                'data' => ['name' => 'Germany', 'states' => [['name' => 'Berlin']]],
            ]),
            'countriesnow.space/*/state/cities/q*' => Http::response([
                'error' => false,
                'data' => ['Berlin', 'Potsdam'],
            ]),
        ]);

        $this->getJson('/checkout-locations/states?country=DE')
            ->assertOk()
            ->assertExactJson(['states' => ['Berlin']]);

        $this->getJson('/checkout-locations/cities?country=DE&state=Berlin')
            ->assertOk()
            ->assertExactJson(['cities' => ['Berlin', 'Potsdam']]);

        $this->getJson('/checkout-locations/cities?country=DE&state=Unknown')->assertStatus(422);
    }

    public function test_location_lookup_failure_returns_service_unavailable_for_manual_entry(): void
    {
        Http::fake(['countriesnow.space/*' => Http::response([], 503)]);

        $this->getJson('/checkout-locations/states?country=DE')
            ->assertStatus(503)
            ->assertExactJson(['states' => []]);
    }

    public function test_fixed_amount_order_is_created_and_redirects_to_gateway(): void
    {
        $link = $this->createLink();

        $response = $this->post('/c/'.$link->slug, $this->validPayload());

        $response->assertRedirect('https://gateway.example.com/pay/abc');

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(CheckoutLink::ORDER_SOURCE, $order->source);
        $this->assertSame(Order::PLATFORM_INVOICE, $order->platform);
        $this->assertSame($link->id, $order->checkout_link_id);
        $this->assertSame($this->application->id, $order->application_id);
        $this->assertSame($this->group->id, $order->payment_group_id);
        $this->assertSame('100.00', $order->amount);
        $this->assertSame('USD', $order->currency);
        $this->assertSame('John', $order->customer_first_name);
        $this->assertSame('john@example.com', $order->customer_email);
        $this->assertSame('DE', $order->shipping_country);
        // 手机号必须被归一化成 E.164 落库，而不是原样保留客户敲的空格。
        $this->assertSame('+4915112345678', $order->customer_phone);

        // 没配商品时会合成一条兜底明细，小计必须等于金额，
        // 否则 OrderCreationService 第 3 步会直接拒单。
        $this->assertSame('100.00', $order->subtotal);
        $this->assertCount(1, $order->items);

        $this->assertSame(1, $link->fresh()->orders_count);
    }

    public function test_customer_cannot_override_amount_on_a_fixed_link(): void
    {
        $link = $this->createLink();

        // 前端字段被改掉、或直接用 curl 提交一个更低的金额。
        $this->post('/c/'.$link->slug, $this->validPayload(['amount' => '0.01']));

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('100.00', $order->amount);
    }

    public function test_range_link_rejects_an_amount_outside_the_range(): void
    {
        $link = $this->createLink([
            'amount_mode' => CheckoutLink::MODE_RANGE,
            'fixed_amount' => null,
            'min_amount' => '10.00',
            'max_amount' => '50.00',
        ]);

        $this->post('/c/'.$link->slug, $this->validPayload(['amount' => '99.00']))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        $this->post('/c/'.$link->slug, $this->validPayload(['amount' => '25.00']))
            ->assertRedirect('https://gateway.example.com/pay/abc');

        $this->assertSame('25.00', Order::withoutGlobalScopes()->firstOrFail()->amount);
    }

    public function test_inactive_link_returns_404(): void
    {
        $link = $this->createLink(['is_active' => false]);

        $this->get('/c/'.$link->slug)->assertNotFound();
        $this->post('/c/'.$link->slug, $this->validPayload())->assertNotFound();
    }

    public function test_link_is_not_served_on_an_unrelated_host(): void
    {
        $link = $this->createLink();

        // 另一个商户已接入的域名不能用来拉起这条链接（钓鱼防护）。
        $this->get('http://evil.example.com/c/'.$link->slug)->assertNotFound();
    }

    public function test_link_is_served_on_its_own_verified_domain(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'verified_at' => now(),
            'cf_ssl_status' => MerchantDomain::CF_STATUS_ACTIVE,
        ]);

        $link = $this->createLink(['merchant_domain_id' => $domain->id]);

        $this->get('http://checkout.tvbox.com/c/'.$link->slug)->assertOk();

        $this->assertSame('https://checkout.tvbox.com/c/'.$link->slug, $link->fresh()->url());
    }

    public function test_honeypot_submission_is_rejected(): void
    {
        $link = $this->createLink();

        $this->post('/c/'.$link->slug, $this->validPayload(['company_website' => 'http://spam.example.com']))
            ->assertSessionHasErrors('company_website');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_submission_faster_than_the_minimum_form_time_is_rejected(): void
    {
        $link = $this->createLink();

        $this->post('/c/'.$link->slug, $this->validPayload([
            'form_token' => Crypt::encryptString((string) time()),
        ]))->assertSessionHasErrors('form_token');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_forged_form_token_is_rejected(): void
    {
        $link = $this->createLink();

        // 明文时间戳（没有经过 Crypt）——脚本最容易想到的伪造方式。
        $this->post('/c/'.$link->slug, $this->validPayload([
            'form_token' => (string) (time() - 600),
        ]))->assertSessionHasErrors('form_token');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_invalid_international_phone_is_rejected(): void
    {
        $link = $this->createLink();

        // 位数明显不足，libphonenumber 按德国规则判定为无效。
        $this->post('/c/'.$link->slug, $this->validPayload(['phone' => '+4912']))
            ->assertSessionHasErrors('phone');

        // 缺国家码同样拒绝：不带 + 无从判断按哪个国家的规则校验。
        $this->post('/c/'.$link->slug, $this->validPayload(['phone' => '15112345678']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_unknown_country_code_is_rejected(): void
    {
        $link = $this->createLink();

        $this->post('/c/'.$link->slug, $this->validPayload(['country' => 'ZZ']))
            ->assertSessionHasErrors('country');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_items_shipping_and_tax_are_carried_into_the_order(): void
    {
        $link = $this->createLink([
            'fixed_amount' => '115.00',
            'shipping_fee' => '10.00',
            'tax' => '5.00',
        ]);

        $link->items()->create([
            'product_name' => 'TV Box',
            'unit_price' => '50.00',
            'quantity' => 2,
            'sort_order' => 0,
        ]);

        $this->post('/c/'.$link->slug, $this->validPayload())
            ->assertRedirect('https://gateway.example.com/pay/abc');

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // 100 + 10 - 0 + 5 = 115，与固定金额一致才建得出来。
        $this->assertSame('100.00', $order->subtotal);
        $this->assertSame('10.00', $order->shipping_fee);
        $this->assertSame('5.00', $order->tax);
        $this->assertSame('115.00', $order->amount);
        $this->assertSame('TV Box', $order->items->first()->product_name);
        $this->assertSame(2, $order->items->first()->quantity);
    }

    /**
     * 收款链接的三个回跳地址由系统生成、指向落地页自己的域名，与应用绑定的
     * 电商站点域名（applications.website）必然不同。这一条守住的是
     * OrderCreationService 第 4 步对 checkout_link 来源的豁免——豁免没生效
     * 的话所有收款链接下单都会被 CALLBACK_DOMAIN_NOT_ALLOWED 拒掉。
     */
    public function test_callback_domain_check_is_skipped_for_checkout_links(): void
    {
        $link = $this->createLink();

        $this->post('/c/'.$link->slug, $this->validPayload())
            ->assertRedirect('https://gateway.example.com/pay/abc');

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertStringContainsString('/c/'.$link->slug.'/success', (string) $order->return_url);
        $this->assertStringContainsString('/c/'.$link->slug.'/cancelled', (string) $order->cancel_url);
        // 链接上没配 notify_url 时保持为空，OrderNotificationService 会跳过通知。
        $this->assertNull($order->notify_url);
    }

    private function createLink(array $overrides = []): CheckoutLink
    {
        return CheckoutLink::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'application_id' => $this->application->id,
            'payment_group_id' => $this->group->id,
            'title' => 'TV box - model 209343 checkout',
            'currency' => 'USD',
            'amount_mode' => CheckoutLink::MODE_FIXED,
            'fixed_amount' => '100.00',
            'shipping_fee' => '0.00',
            'tax' => '0.00',
            'discount' => '0.00',
            'is_active' => true,
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+49 151 1234 5678',
            'address_line1' => 'Alexanderplatz 1',
            'city' => 'Berlin',
            'state' => 'Berlin',
            'country' => 'DE',
            'zip' => '10178',
            // 模拟页面已经打开了一会儿，绕过"最短填表耗时"的拦截。
            'form_token' => Crypt::encryptString((string) (time() - 600)),
        ], $overrides);
    }
}

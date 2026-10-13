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

    private PaymentMethod $method;

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

        $this->method = PaymentMethod::create([
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

        $this->group->paymentMethods()->attach($this->method->id, ['priority' => 100]);

        // MATCH 模式下，同步给电商站点的明细是从站点商品里凑出来的，不是链接上
        // 配的商品（见 create_checkout_link_items_table 的注释）。这里备一批变体
        // 供凑单算法使用——总容量（单价 × 单品件数上限之和）必须大于测试里用到的
        // 最大订单金额，否则 OrderItemService 会以"站点商品容量不足"直接抛异常。
        $product = SiteProduct::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $this->method->id,
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

        // 原始商品明细保留 100；匹配超额增加 subtotal 与 discount，最终金额不变。
        $this->assertSame('100.00', $order->items->first()->total_price);
        $this->assertSame('100.00', bcsub($order->subtotal, $order->discount, 2));
        // 没配商品时会合成一条兜底明细，明细之和必须等于金额，
        // 否则 OrderCreationService 第 3 步会直接拒单。
        //
        // ⚠️ 断言"原始明细之和"而不是 orders.subtotal：MATCH 模式会在建远端支付单时
        // 把从站点商品凑单产生的**超额**写回 orders.subtotal（并等额计入 discount，
        // 保证应付金额不变，见 OrderCreationService::createRemotePayment）。凑单本身是
        // 随机算法（shuffle / random_int），所以 subtotal 不是稳定值；原始明细不受匹配
        // 影响，匹配结果另存 order_matched_items。
        $this->assertCount(1, $order->items);
        $this->assertSame('100.00', bcadd((string) $order->items->sum('total_price'), '0', 2));

        $this->assertSame(1, $link->fresh()->orders_count);
    }

    public function test_disabled_auto_redirect_displays_payment_url_without_creating_another_order(): void
    {
        $link = $this->createLink(['auto_redirect' => false]);
        $this->post('/c/'.$link->slug, $this->validPayload(['auto_redirect' => true]))
            ->assertRedirect(route('checkout.payment', ['slug' => $link->slug]));

        $this->get('/c/'.$link->slug.'/payment')
            ->assertOk()->assertViewIs('checkout.payment')
            ->assertSee('https://gateway.example.com/pay/abc');
        $this->get('/c/'.$link->slug.'/payment')->assertOk();
        $this->assertSame(1, Order::withoutGlobalScopes()->count());

        $other = $this->createLink(['auto_redirect' => false]);
        $this->get('/c/'.$other->slug.'/payment')->assertNotFound();
        $link->update(['is_active' => false]);
        $this->get('/c/'.$link->slug.'/payment')->assertNotFound();
    }

    public function test_payment_url_page_requires_matching_session_and_host(): void
    {
        $link = $this->createLink(['auto_redirect' => false]);
        $this->get('/c/'.$link->slug.'/payment')->assertNotFound();
        $this->post('/c/'.$link->slug, $this->validPayload())->assertRedirect();
        $this->get('https://untrusted.example/c/'.$link->slug.'/payment')->assertNotFound();
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

        // 匹配超额由折扣抵扣，商品净额仍为 100，运费与税费保持不变。
        $this->assertSame('100.00', $order->items->first()->total_price);
        $this->assertSame('100.00', bcsub($order->subtotal, $order->discount, 2));
        // 100 + 10 - 0 + 5 = 115，与固定金额一致才建得出来。
        // 同 test_fixed_amount_order_is_created_and_redirects_to_gateway：orders.subtotal
        // 会被 MATCH 凑单的超额改写，只有原始明细之和是稳定的。
        $this->assertSame('100.00', bcadd((string) $order->items->sum('total_price'), '0', 2));
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

    // ---------------------------------------------------------------------
    // 支付组选择（需求：链接多选支付组 + 落地页点选，最终渠道仍由算法决定）
    // ---------------------------------------------------------------------

    /**
     * 需求 2/3：链接配了可选支付组时，落地页出现选择区，每个组一张可点卡片。
     * 这两个组都没配 Logo，所以卡片回退展示组名兜底。
     */
    public function test_landing_page_lists_the_selectable_payment_groups(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $link = $this->createLink(['payment_group_ids' => [$this->group->id, $second->id]]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('Payment Method')
            ->assertSee('name="payment_group_id"', false)
            ->assertSee('value="'.$this->group->id.'"', false)
            ->assertSee('value="'.$second->id.'"', false)
            ->assertSee('Default Group')
            ->assertSee('Alternative Group');
    }

    /**
     * 默认组自动并入候选：运营没在「可选择的支付组」里重复勾默认组时，
     * 客户也应该能在收款页上看到并选择它。
     *
     * 回归背景：早期实现只渲染 payment_group_ids 里的组，默认组不在其中就从客户
     * 眼前整块消失——后台那个必填字段退化成隐形配置，运营以为它在生效，实际客户
     * 永远选不到，只能等线上投诉才发现。
     */
    public function test_default_group_is_offered_even_when_it_is_not_selected(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');

        // 只勾了第二个组，默认组（$this->group）故意不勾。
        $link = $this->createLink(['payment_group_ids' => [$second->id]]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('id="payment_group_'.$second->id.'"', false)
            // 默认组照样出现在候选里
            ->assertSee('id="payment_group_'.$this->group->id.'"', false)
            ->assertSee('Default Group');
    }

    /**
     * 默认组已经被勾进「可选择的支付组」时，候选里**只能出现一次**。
     *
     * 合并是并集而不是简单追加：否则页面会渲染出两张一模一样的卡片，
     * 同一 id 的两个 radio 还会互相抢选中态。
     *
     * 断言用 id="payment_group_N" 而不是 value="N"——页面上别的
     * input（金额、国家、隐藏字段）也可能带着同样的数字值。
     */
    public function test_default_group_is_listed_only_once_when_also_selected(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $link = $this->createLink(['payment_group_ids' => [$this->group->id, $second->id]]);

        $content = $this->get('/c/'.$link->slug)->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'id="payment_group_'.$this->group->id.'"'));
        $this->assertSame(1, substr_count($content, 'id="payment_group_'.$second->id.'"'));
    }

    /**
     * 默认组被停用后同样不出现——并集不豁免启用校验，标准与可选组完全一致。
     * 渲染一个点进去必然失败的选项，比不渲染更糟（客户会以为是自己操作错了）。
     */
    public function test_a_disabled_default_group_is_not_offered(): void
    {
        $this->group->update(['is_active' => false]);
        $second = $this->createGroup('group_alt', 'Alternative Group');

        $link = $this->createLink(['payment_group_ids' => [$second->id]]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertSee('id="payment_group_'.$second->id.'"', false)
            ->assertDontSee('id="payment_group_'.$this->group->id.'"', false)
            ->assertDontSee('Default Group');
    }

    /**
     * 交互优化：只有一个可选支付组时默认勾上，客户不必为唯一的选项多点一次。
     * 只是「预选」，不是锁死 —— 控件仍可改。
     */
    public function test_a_single_selectable_group_is_preselected(): void
    {
        $link = $this->createLink(['payment_group_ids' => [$this->group->id]]);

        $content = $this->get('/c/'.$link->slug)->assertOk()->getContent();

        // [^>]*? 把匹配范围锁在这个 <input> 标签内部，不会串到别的元素上。
        $this->assertMatchesRegularExpression(
            '/id="payment_group_'.$this->group->id.'"[^>]*?checked/',
            $content
        );
    }

    /**
     * 回归护栏：「客户必选」是既定决策 Q2-A。摆着多个组时**不能**替客户预选，
     * 否则预选逻辑一旦写错（比如无脑选中第一个），这条业务规则就形同虚设。
     *
     * ⚠️ 断言必须盯住 <input> 标签内部：页面上还有 peer-checked: 这类类名，
     * 里面也带 "checked" 字样，宽泛的 assertDontSee('checked') 会误报。
     */
    public function test_no_group_is_preselected_when_multiple_are_offered(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $link = $this->createLink(['payment_group_ids' => [$this->group->id, $second->id]]);

        $content = $this->get('/c/'.$link->slug)->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="payment_group_\d+"[^>]*?checked/', $content);
    }

    /**
     * 组未配 Logo 时回退到链接自身的 Logo（header 里那张，决策 Q3）：
     * 配了 Logo 的组用它自己的图，没配的组复用链接 Logo，而不是把内部组名怼给客户。
     */
    public function test_group_card_falls_back_to_the_link_logo_when_the_group_has_none(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $second->update(['logo_path' => 'payment-groups/logos/alt.png']);

        $link = $this->createLink([
            'logo_path' => 'checkout-links/logos/link.png',
            'payment_group_ids' => [$this->group->id, $second->id],
        ]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            // 配了 Logo 的组展示自己的
            ->assertSee('payment-groups/logos/alt.png')
            // 没配 Logo 的组（默认组）回退到链接自身的 Logo
            ->assertSee('checkout-links/logos/link.png');
    }

    /**
     * 没配可选组时不渲染选择区（存量链接零迁移零回归），下单仍走默认组。
     */
    public function test_landing_page_hides_the_selector_when_no_groups_are_configured(): void
    {
        $link = $this->createLink();

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertDontSee('name="payment_group_id"', false);

        $this->post('/c/'.$link->slug, $this->validPayload())
            ->assertRedirect('https://gateway.example.com/pay/abc');

        $this->assertSame(
            $this->group->id,
            Order::withoutGlobalScopes()->firstOrFail()->payment_group_id
        );
    }

    /**
     * 停用的组既不出现在候选里，也不接受硬提交（决策：只使用已启用的支付组）。
     */
    public function test_only_enabled_groups_are_offered_and_accepted(): void
    {
        $disabled = $this->createGroup('group_off', 'Disabled Group', isActive: false);
        $link = $this->createLink(['payment_group_ids' => [$this->group->id, $disabled->id]]);

        $this->get('/c/'.$link->slug)
            ->assertOk()
            ->assertDontSee('value="'.$disabled->id.'"', false)
            ->assertDontSee('Disabled Group');

        $this->post('/c/'.$link->slug, $this->validPayload(['payment_group_id' => $disabled->id]))
            ->assertSessionHasErrors('payment_group_id');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    /**
     * 决策 Q2-A：展示选择区时客户必选，不选直接打回。
     */
    public function test_customer_must_choose_a_group_when_the_selector_is_shown(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $link = $this->createLink(['payment_group_ids' => [$this->group->id, $second->id]]);

        $this->post('/c/'.$link->slug, $this->validPayload())
            ->assertSessionHasErrors('payment_group_id');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    /**
     * 核心：客户选的组决定订单挂到哪个支付组；而组内具体走哪条通道仍由
     * PaymentService 的算法决定 —— 验证方式是没有落下指定渠道标记
     * （designated_payment_method_key 为空 = 没有走进指定渠道旁路，也就没有跳过风控）。
     */
    public function test_order_uses_the_group_selected_by_the_customer(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $link = $this->createLink(['payment_group_ids' => [$this->group->id, $second->id]]);

        $this->post('/c/'.$link->slug, $this->validPayload(['payment_group_id' => $second->id]))
            ->assertRedirect('https://gateway.example.com/pay/abc');

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($second->id, $order->payment_group_id);
        $this->assertSame($this->method->id, $order->payment_method_id);
        $this->assertNull($order->designated_payment_method_key);
    }

    /**
     * 与"默认组自动并入候选"配套的正向用例：默认组没被勾进可选组、但处于启用状态时，
     * 客户选它必须能一路下单成功，订单挂在默认组上。
     *
     * 这一条才真正证明它"可用"——只断言页面渲染出来是不够的，
     * FormRequest 的 in 白名单若没跟着并入，客户点下去照样被打回。
     */
    public function test_customer_can_order_with_the_default_group_that_was_not_selected(): void
    {
        $second = $this->createGroup('group_alt', 'Alternative Group');
        $link = $this->createLink(['payment_group_ids' => [$second->id]]);

        $this->post('/c/'.$link->slug, $this->validPayload(['payment_group_id' => $this->group->id]))
            ->assertRedirect('https://gateway.example.com/pay/abc');

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->group->id, $order->payment_group_id);
        $this->assertSame($this->method->id, $order->payment_method_id);
        // 仍然是"只交出组"：组内通道由算法决定，没有落下指定渠道标记。
        $this->assertNull($order->designated_payment_method_key);
    }

    /**
     * 硬约束回归：哪怕有人在表单里硬塞一个 payment_method_key，也不能影响通道选择
     * —— CheckoutLinkOrderService 压根不读这个字段，订单上的指定渠道标记必须保持为空。
     */
    public function test_a_smuggled_payment_method_key_is_ignored(): void
    {
        $link = $this->createLink();

        $this->post('/c/'.$link->slug, $this->validPayload([
            'payment_method_key' => 'some_gateway_code',
        ]))->assertRedirect('https://gateway.example.com/pay/abc');

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertNull($order->designated_payment_method_key);
        $this->assertSame($this->method->id, $order->payment_method_id);
    }

    /**
     * 再建一个隶属于同一商户的支付组。组里必须挂上可用的支付方式，否则下单时
     * PaymentService 会因为"组内无可用通道"直接抛 NoAvailablePaymentMethodException
     * （API 层 409），测不到组路由本身。
     */
    private function createGroup(string $groupKey, string $groupName, bool $isActive = true): PaymentGroup
    {
        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => $groupKey,
            'group_name' => $groupName,
            'is_active' => $isActive,
        ]);

        $group->paymentMethods()->attach($this->method->id, ['priority' => 100]);

        return $group;
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

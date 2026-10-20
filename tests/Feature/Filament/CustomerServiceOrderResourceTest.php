<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CustomerServiceOrderResource;
use App\Filament\Resources\CustomerServiceOrderResource\Pages\ListCustomerServiceOrders;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\MerchantRoleProvisioningService;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 客服订单查询模块的行为约束。
 *
 * 这个模块的价值在于"给客服一个只看得到该看的字段的入口"，所以测试重点
 * 不在"列表能不能显示"，而在三件事：
 *   1. 列与筛选**只有**约定的八项，多一列少一列都不行；
 *   2. 没有统计汇总、没有"查看"详情页——这两样会把客服不该看的信息
 *      （结算金额、物流、商户名）或不该进的操作带进来；
 *   3. 客服角色只拿到这一个权限，不会顺带获得完整订单列表入口。
 */
class CustomerServiceOrderResourceTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('merchant');
    }

    /**
     * 约定的七个表格列。
     *
     * 需求里的八项是「系统订单号/商户订单号/原始币种金额/三方交易号/客户邮箱/
     * 订单状态/开始时间/结束时间」，其中开始时间与结束时间对应的是**时间范围
     * 筛选**（created_from / created_to），列表上对应的可见列是「创建时间」，
     * 所以表格列是七项。
     */
    private const EXPECTED_COLUMNS = [
        'order_no',
        'merchant_order_no',
        'transaction_id',
        'customer_email',
        'amount',
        'status',
        'created_at',
    ];

    /** 约定的八个筛选项。 */
    private const EXPECTED_FILTERS = [
        'order_no',
        'merchant_order_no',
        'amount',
        'transaction_id',
        'customer_email',
        'status',
        'created_from',
        'created_to',
    ];

    // ------------------------------------------------------------------
    // 翻译完整性
    // ------------------------------------------------------------------

    /**
     * 角色权限分配页把角色名和权限名都从语言包取：
     *   角色 → admin.role_permissions.roles.{key}
     *   权限 → admin.role_permissions.permissions.{权限名把点换成下划线}
     * 翻译键缺失时 Laravel 会回退显示英文原文（权限名会变成
     * 'admin.role_permissions.permissions.customer_service_orders_view' 这种
     * 一长串），在页面上非常显眼。这里锁住两个语言都有译文。
     */
    public function test_the_customer_service_role_and_permission_have_translations(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            app()->setLocale($locale);

            $roleLabel = __('admin.role_permissions.roles.customer_service');
            $permissionLabel = __('admin.role_permissions.permissions.customer_service_orders_view');

            $this->assertNotSame(
                'admin.role_permissions.roles.customer_service',
                $roleLabel,
                "客服角色名在 {$locale} 下缺少翻译"
            );
            $this->assertNotSame(
                'admin.role_permissions.permissions.customer_service_orders_view',
                $permissionLabel,
                "客服权限名在 {$locale} 下缺少翻译"
            );
            $this->assertNotSame('', trim($roleLabel));
            $this->assertNotSame('', trim($permissionLabel));
        }
    }

    // ------------------------------------------------------------------
    // 权限
    // ------------------------------------------------------------------

    public function test_it_is_hidden_without_the_customer_service_permission(): void
    {
        $this->createMerchantAndApplication();
        Livewire::actingAs(User::factory()->create(['merchant_id' => $this->merchant->id]));

        $this->assertFalse(CustomerServiceOrderResource::canViewAny());
    }

    public function test_it_is_visible_with_the_customer_service_permission(): void
    {
        $this->createMerchantAndApplication();
        Livewire::actingAs($this->customerServiceUser());

        $this->assertTrue(CustomerServiceOrderResource::canViewAny());
    }

    public function test_the_customer_service_role_grants_only_the_new_permission(): void
    {
        $this->createMerchantAndApplication();

        $service = app(MerchantRoleProvisioningService::class);
        $method = new ReflectionMethod($service, 'builtInDefinitions');
        $method->setAccessible(true);
        $builtIn = $method->invoke($service);

        $this->assertArrayHasKey('customer_service', $builtIn, '应存在客服角色定义');
        $this->assertSame('客服订单查看', $builtIn['customer_service']['label']);
        $this->assertSame(
            [Permissions::CUSTOMER_SERVICE_ORDERS_VIEW],
            $builtIn['customer_service']['permissions'],
            '客服角色只能有客服订单查询这一个权限，不含 ORDERS_VIEW'
        );
    }

    // ------------------------------------------------------------------
    // 列：只有约定的六项
    // ------------------------------------------------------------------

    public function test_the_table_only_exposes_the_agreed_columns(): void
    {
        $table = $this->tableDefinition();

        // 恰好是约定的六列，不多不少
        $this->assertEqualsCanonicalizing(
            self::EXPECTED_COLUMNS,
            array_keys($table->getColumns())
        );
    }

    public function test_the_table_does_not_expose_settlement_merchant_or_shipping_columns(): void
    {
        $columns = array_keys($this->tableDefinition()->getColumns());

        // 这几列在完整订单列表里都有，但涉及平台分成、商户信息或物流，
        // 不属于客服职责范围。
        foreach (['settlement_amount', 'converted_amount', 'merchant.name', 'shipping.tracking_number'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "客服列表不应包含 {$forbidden}");
        }
    }

    // ------------------------------------------------------------------
    // 筛选条件：八项
    // ------------------------------------------------------------------

    public function test_all_eight_agreed_filters_exist(): void
    {
        $filters = array_keys($this->tableDefinition()->getFilters());

        $this->assertEqualsCanonicalizing(self::EXPECTED_FILTERS, $filters);
    }

    public function test_it_filters_by_exact_order_no(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $hit = $this->makeOrder('CS-EXACT-1', 'MO-CS-1');
        $miss = $this->makeOrder('CS-EXACT-2', 'MO-CS-2');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->filterTable('order_no', ['order_no' => 'CS-EXACT-1'])
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords([$miss]);
    }

    public function test_an_invalid_amount_filter_returns_no_rows_instead_of_ignoring_the_filter(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-AMOUNT-1', 'MO-CS-AMOUNT');

        // 填了非法金额时必须返回空集：悄悄忽略条件会让客服照着不相关的结果
        // 回答客户，比明确"查不到"危险得多。
        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->filterTable('amount', ['amount' => 'abc'])
            ->assertCanNotSeeTableRecords([$order]);
    }

    public function test_it_filters_by_created_at_range(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-RANGE-1', 'MO-CS-RANGE');

        // makeOrder() 不接受 created_at（Eloquent 的 timestamps 会在 create()
        // 时覆盖传入值），所以要改时间必须先建再单独写一次。
        $threeDaysAgo = now()->subDays(3)->startOfSecond();
        $order->forceFill(['created_at' => $threeDaysAgo])->save();
        $order->refresh();

        // 起始时间早于订单创建时间 → 筛中
        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->filterTable('created_from', ['created_from' => now()->subDays(7)->format('Y-m-d H:i:s')])
            ->assertCanSeeTableRecords([$order]);

        // 起始时间晚于订单创建时间 → 筛不到
        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->filterTable('created_from', ['created_from' => now()->subDay()->format('Y-m-d H:i:s')])
            ->assertCanNotSeeTableRecords([$order]);
    }
    // ------------------------------------------------------------------
    // 取消的两项：统计汇总、查看详情
    // ------------------------------------------------------------------

    public function test_it_has_no_summary_statistics(): void
    {
        $user = $this->customerServiceUserWithMerchant();

        // 汇总会在页脚暴露金额口径合计——客服不需要，也不该看到。
        // 直接检查 Table 定义：本模块没有调用任何 ->summarizer()，
        // 所以六列的 summarizers 都应为空数组。
        $page = Livewire::actingAs($user)->test(ListCustomerServiceOrders::class);
        $table = $page->instance()->getTable();

        foreach (self::EXPECTED_COLUMNS as $column) {
            $this->assertSame(
                [],
                $table->getColumn($column)?->getSummarizers() ?? [],
                "客服列表的 {$column} 列不应挂任何汇总统计"
            );
        }
    }

    public function test_it_has_no_view_record_action(): void
    {
        $this->createMerchantAndApplication();
        $order = $this->makeOrder('CS-NOVIEW-1', 'MO-CS-NOVIEW');

        // 没有详情页：canView 为 false，因此不会有可点的"查看"入口。
        $this->assertFalse(CustomerServiceOrderResource::canView($order));
        $this->assertNotContains('view', $this->recordActionNames(), '客服列表不应有"查看"操作');
    }

    public function test_it_keeps_only_the_query_order_status_action(): void
    {
        $actions = $this->recordActionNames();

        $this->assertSame(['queryOrderStatus'], $actions, '只应保留"查询订单"这一个行操作');

        // 客服不应拿到任何写操作：发货、退款、拒付、手改状态都不该出现。
        foreach (['ship', 'refund', 'chargeback', 'manualStatus'] as $forbidden) {
            $this->assertNotContains($forbidden, $actions, "客服列表不应有 {$forbidden} 操作");
        }
    }

    // ------------------------------------------------------------------
    // 查询订单：保留完整能力
    // ------------------------------------------------------------------

    public function test_the_query_status_action_can_flip_an_order_to_paid(): void
    {
        $user = $this->customerServiceUserWithMerchant();

        // 订单必须绑定带网关凭证的支付方式，queryAndApply() 才会发起查询——
        // 它从 paymentMethodConfig() 取 domain / client_id / client_sk，
        // 缺任何一个都会抛 RuntimeException 而不是静默跳过。
        $method = $this->makePaymentMethod('stripe');
        $order = $this->makeOrder('CS-QUERY-1', 'MO-CS-QUERY', [
            'status' => 'pending',
            'payment_method' => $method->method_code,
            'payment_method_id' => $method->id,
            'settlement_amount' => '90.00',
            'notify_url' => 'https://merchant.example/callback',
        ]);

        // 客服要靠它回答"我付没付钱"，因此必须能真实改变订单状态并补做入账。
        $this->fakeGatewayQuery('paid');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->callAction(TestAction::make('queryOrderStatus')->table($order));

        $this->assertSame('paid', $order->refresh()->status);
    }

    // ------------------------------------------------------------------
    // 数据隔离：客服只看得到名下商户的订单
    // ------------------------------------------------------------------

    public function test_it_does_not_show_other_merchants_orders(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $ownOrder = $this->makeOrder('CS-OWN', 'MO-OWN');

        // 借 makeOrder() 造一条字段齐全的订单，再把商户改到别人名下——
        // 直接 create() 要手写七十多个 NOT NULL 字段，漏一个就是约束报错。
        $otherOrder = $this->makeOrder('CS-OTHER', 'MO-OTHER');
        $otherOrder->forceFill(['merchant_id' => Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other',
            'contact_phone' => '1234567',
            'contact_email' => 'other@example.com',
        ])->id])->save();

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->assertCanSeeTableRecords([$ownOrder])
            ->assertCanNotSeeTableRecords([$otherOrder]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** 建商户 + 带客服权限的用户，并返回该用户。 */
    private function customerServiceUserWithMerchant(): User
    {
        $this->createMerchantAndApplication();

        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW);

        return $user;
    }

    /** 本模块注册的全部行操作名。 */
    private function recordActionNames(): array
    {
        $this->createMerchantAndApplication();

        return array_values(array_map(
            fn ($action) => $action->getName(),
            array_values($this->tableDefinition()->getRecordActions())
        ));
    }

    /**
     * 直接拿到本模块的 Table 定义。
     *
     * 断言"有哪些列/筛选/汇总"时读 Table 定义比走 Livewire 的
     * assertTableColumnExists 之类 DSL 更直接：DSL 只验证"存在某个名字"，
     * 而这里能一次拿到完整清单，天然支持"恰好是这几项"的断言
     * （多一列少一列都能发现）。
     */
    private function tableDefinition(): Table
    {
        $user = $this->customerServiceUserWithMerchant();

        return Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->instance()
            ->getTable();
    }

    /** 只造用户，商户由调用方提前建好。 */
    private function customerServiceUser(): User
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW);

        return $user;
    }

    /**
     * 让 OrderPaymentStatusService 的网关查询返回指定状态。
     * 走 HTTP fake 而不是碰真实网关——本用例只验证"客服能触发查询动作"，
     * 插件侧契约由 SyncPendingOrderStatusesTest 那组用例负责。
     */
    private function fakeGatewayQuery(string $status): void
    {
        // preventStrayRequests：网关查询走了未预置的 URL 时立刻报错，
        // 而不是静默发出真实请求——否则本地测试可能打到真的插件站上。
        Http::preventStrayRequests();
        Http::fake([
            '*/wp-json/payment-plugin/v1/order-query*' => Http::response([
                'code' => 0,
                'data' => [
                    'status' => $status,
                    'transaction_id' => 'pi_cs_test',
                    'wp_order_id' => 456,
                ],
            ]),
        ]);
    }
    // ------------------------------------------------------------------
    // 角色
    // ------------------------------------------------------------------
}

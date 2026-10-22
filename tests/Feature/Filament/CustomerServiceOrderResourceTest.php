<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AdminDashboard;
use App\Filament\Resources\CarrierResource;
use App\Filament\Resources\CheckoutLinkResource;
use App\Filament\Resources\CustomerServiceOrderResource;
use App\Filament\Resources\CustomerServiceOrderResource\Pages\ListCustomerServiceOrders;
use App\Filament\Widgets\OrderTrendChart;
use App\Filament\Widgets\PaymentMethodBreakdownChart;
use App\Filament\Widgets\PaymentSuccessRate;
use App\Filament\Widgets\StatsOverview;
use App\Models\CheckoutLink;
use App\Models\Merchant;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\MerchantRoleProvisioningService;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Tables\Table;
use Filament\Widgets\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 客服订单查询模块的行为约束。
 *
 * 这个模块的价值在于"给客服一个只看得到该看的字段的入口"，所以测试重点
 * 不在"列表能不能显示"，而在四件事：
 *   1. 列与筛选只有约定的七列和五项条件；
 *   2. 没有统计汇总、没有"查看"详情页——这两样会把客服不该看的信息
 *      （结算金额、物流、商户名）或不该进的操作带进来；
 *   3. 客服角色只拿到这一个权限，不会顺带获得完整订单列表入口。
 *   4. 至少填写一个条件并点击查询后，才展示匹配订单。
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

    /** 约定的七个表格列，仍展示订单状态与创建时间。 */
    private const EXPECTED_COLUMNS = [
        'order_no',
        'merchant_order_no',
        'transaction_id',
        'customer_email',
        'amount',
        'status',
        'created_at',
    ];

    /** 五项查询条件，至少填写其中一项。 */
    private const EXPECTED_FILTERS = [
        'order_no',
        'merchant_order_no',
        'amount',
        'transaction_id',
        'customer_email',
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

    public function test_the_customer_service_role_only_sees_the_customer_service_query_navigation(): void
    {
        $user = $this->userWithDefaultMerchantRole('客服订单查看');
        $this->actingAs($user);

        $navigationUrls = collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group): array => collect($group->getItems())->all())
            ->map(fn (NavigationItem $item): ?string => $item->getUrl())
            ->all();

        $this->assertSame([CustomerServiceOrderResource::getUrl()], $navigationUrls);

        foreach (Filament::getCurrentPanel()->getResources() as $resource) {
            $this->assertSame(
                $resource === CustomerServiceOrderResource::class,
                $resource::canViewAny(),
                "客服账号对 {$resource} 的访问权限不符合预期",
            );
        }

        foreach (Filament::getCurrentPanel()->getPages() as $page) {
            $this->assertFalse($page::canAccess(), "客服账号不应访问 {$page}");
        }

        Livewire::test(ListCustomerServiceOrders::class)->assertOk();
    }

    public function test_the_customer_service_role_is_redirected_from_the_panel_home_to_the_query_page(): void
    {
        $user = $this->userWithDefaultMerchantRole('客服订单查看');

        $this->actingAs($user)
            ->get(route('filament.merchant.home'))
            ->assertRedirect(CustomerServiceOrderResource::getUrl());
    }

    public function test_the_customer_service_role_cannot_open_other_pages_directly(): void
    {
        $user = $this->userWithDefaultMerchantRole('客服订单查看');
        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'customer_service_test',
            'group_name' => 'Customer service test',
            'is_active' => true,
        ]);
        $link = CheckoutLink::create([
            'merchant_id' => $this->merchant->id,
            'application_id' => $this->application->id,
            'payment_group_id' => $group->id,
            'title' => 'Customer checkout',
            'link_name' => 'Private checkout link',
            'currency' => 'USD',
            'amount_mode' => CheckoutLink::MODE_FIXED,
            'fixed_amount' => '10.00',
        ]);
        $this->actingAs($user);

        foreach ([
            AdminDashboard::getUrl(),
            CarrierResource::getUrl(),
            CheckoutLinkResource::getUrl(),
            CheckoutLinkResource::getUrl('create'),
            CheckoutLinkResource::getUrl('edit', ['record' => $link]),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_merchant_admin_keeps_dashboard_and_carrier_access(): void
    {
        $user = $this->userWithDefaultMerchantRole('商户管理员');
        $this->actingAs($user);

        $this->assertTrue(AdminDashboard::canAccess());
        $this->assertTrue(CarrierResource::canViewAny());
        $this->get(route('filament.merchant.home'))->assertRedirect(AdminDashboard::getUrl());
        $this->get(AdminDashboard::getUrl())->assertOk();
        $this->get(CarrierResource::getUrl())->assertOk();
    }

    public function test_a_super_admin_keeps_dashboard_and_carrier_access(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
        $user->givePermissionTo(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW);
        Filament::setCurrentPanel('admin');
        $this->actingAs($user);

        $this->assertTrue(AdminDashboard::canAccess());
        $this->assertTrue(CarrierResource::canViewAny());
        $this->get(route('filament.admin.home'))->assertRedirect(AdminDashboard::getUrl());
        $this->get(AdminDashboard::getUrl())->assertOk();
        $this->get(CarrierResource::getUrl())->assertOk();
    }

    #[TestWith(['role'])]
    #[TestWith(['direct'])]
    public function test_additional_permissions_preserve_a_customer_service_users_other_authorized_pages(string $permissionSource): void
    {
        $user = $this->userWithDefaultMerchantRole('客服订单查看');

        if ($permissionSource === 'role') {
            $user->assignRole(Role::query()
                ->where('merchant_id', $this->merchant->id)
                ->where('name', '订单管理员')
                ->firstOrFail());
        } else {
            $user->givePermissionTo(Permissions::CHECKOUT_LINKS_MANAGE);
        }

        $this->actingAs($user->fresh());

        $this->assertTrue(CustomerServiceOrderResource::canViewAny());
        $this->assertTrue(CheckoutLinkResource::canViewAny());
        $this->assertTrue(AdminDashboard::canAccess());
        $this->assertTrue(CarrierResource::canViewAny());
        $this->get(CheckoutLinkResource::getUrl())->assertOk();
    }

    /** @param class-string<Widget> $widgetClass */
    #[TestWith([StatsOverview::class])]
    #[TestWith([PaymentSuccessRate::class])]
    #[TestWith([OrderTrendChart::class])]
    #[TestWith([PaymentMethodBreakdownChart::class])]
    public function test_dashboard_widgets_reject_existing_snapshots_after_access_is_restricted_to_customer_service(string $widgetClass): void
    {
        $user = $this->userWithDefaultMerchantRole('商户管理员');
        $this->actingAs($user);
        $widget = Livewire::withoutLazyLoading()->test($widgetClass)->assertOk();

        $user->syncRoles(Role::query()
            ->where('merchant_id', $this->merchant->id)
            ->where('name', '客服订单查看')
            ->firstOrFail());
        $this->actingAs($user->fresh());

        $this->assertFalse($widgetClass::canView());
        $widget->call('$refresh')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // 列：只有约定的七项
    // ------------------------------------------------------------------

    public function test_the_table_only_exposes_the_agreed_columns(): void
    {
        $table = $this->tableDefinition();

        // 恰好是约定的七列，不多不少
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
    // 查询条件：五项，提交后才显示结果
    // ------------------------------------------------------------------

    public function test_only_the_five_agreed_filters_exist(): void
    {
        $table = $this->tableDefinition();
        $filters = array_keys($table->getFilters());

        $this->assertEqualsCanonicalizing(self::EXPECTED_FILTERS, $filters);
        $this->assertFalse($table->isSearchable());
    }

    public function test_it_does_not_show_orders_before_searching(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $this->makeOrder('CS-INITIAL-1', 'MO-CS-INITIAL');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->assertCountTableRecords(0);
    }

    public function test_orders_are_hidden_when_search_conditions_are_restored_from_the_url(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $this->makeOrder('CS-URL-1', 'MO-CS-URL');

        Livewire::actingAs($user)
            ->withQueryParams(['filters' => ['order_no' => ['order_no' => 'CS-URL-1']]])
            ->test(ListCustomerServiceOrders::class)
            ->assertSet('tableDeferredFilters.order_no.order_no', 'CS-URL-1')
            ->assertCountTableRecords(0)
            ->call('searchOrders')
            ->assertCountTableRecords(1);
    }

    public function test_entering_a_condition_does_not_show_results_until_search_is_clicked(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-DEFERRED-1', 'MO-CS-DEFERRED');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters.order_no.order_no', 'CS-DEFERRED-1')
            ->assertCountTableRecords(0)
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$order]);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_empty_or_whitespace_only_conditions_are_rejected(?string $value): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $this->makeOrder('CS-EMPTY-1', 'MO-CS-EMPTY');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters', [
                'order_no' => ['order_no' => $value],
                'merchant_order_no' => ['merchant_order_no' => $value],
                'amount' => ['amount' => $value],
                'transaction_id' => ['transaction_id' => $value],
                'customer_email' => ['customer_email' => $value],
            ])
            ->call('searchOrders')
            ->assertCountTableRecords(0)
            ->assertNotified(__('admin.customer_service_order.search.required'));
    }

    #[TestWith(['order_no', ' CS-MATCH-1 ', []])]
    #[TestWith(['merchant_order_no', ' MO-CS-1 ', []])]
    #[TestWith(['amount', ' 100.00 ', []])]
    #[TestWith(['transaction_id', ' pi_target ', []])]
    #[TestWith(['customer_email', ' target@ ', []])]
    #[TestWith(['amount', '0', ['amount' => '0.00']])]
    #[TestWith(['transaction_id', '0', ['transaction_id' => 'pi_0']])]
    #[TestWith(['customer_email', '0', ['customer_email' => '0@example.com']])]
    public function test_each_condition_can_search_for_matching_orders_on_its_own(string $field, string $value, array $overrides): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $hit = $this->makeOrder('CS-MATCH-1', 'MO-CS-1', array_merge([
            'transaction_id' => 'pi_target',
            'customer_email' => 'target@example.com',
        ], $overrides));
        $miss = $this->makeOrder('CS-OTHER-2', 'MO-OTHER-2', [
            'amount' => '100.01',
            'transaction_id' => 'pi_other',
            'customer_email' => 'other@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set("tableDeferredFilters.{$field}.{$field}", $value)
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords([$miss]);
    }

    #[TestWith(['order_no'])]
    #[TestWith(['merchant_order_no'])]
    #[TestWith(['transaction_id'])]
    public function test_identifier_filters_match_contained_text_even_when_a_complete_number_is_entered(string $field): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $hit = $this->makeOrder('CS-FUZZY-1', 'MO-FUZZY-1', [$field => 'PREFIX-MATCH-1']);
        $similar = $this->makeOrder('CS-FUZZY-2', 'MO-FUZZY-2', [$field => 'PREFIX-MATCH-10']);
        $miss = $this->makeOrder('CS-OTHER-3', 'MO-OTHER-3', [$field => 'PREFIX-OTHER-3']);

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set("tableDeferredFilters.{$field}.{$field}", ' MATCH ')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$hit, $similar])
            ->assertCanNotSeeTableRecords([$miss])
            ->set("tableDeferredFilters.{$field}.{$field}", 'PREFIX-MATCH-1')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$hit, $similar])
            ->assertCanNotSeeTableRecords([$miss]);
    }

    public function test_all_filled_conditions_must_match_the_order(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $hit = $this->makeOrder('CS-COMBINED-1', 'MO-CS-COMBINED-1', ['amount' => '10.00']);
        $sameEmail = $this->makeOrder('CS-COMBINED-2', 'MO-CS-COMBINED-2', ['amount' => '20.00']);
        $sameAmount = $this->makeOrder('CS-COMBINED-3', 'MO-CS-COMBINED-3', [
            'amount' => '10.00',
            'customer_email' => 'other@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters.customer_email.customer_email', 'john@example.com')
            ->set('tableDeferredFilters.amount.amount', '10.00')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$hit])
            ->assertCanNotSeeTableRecords([$sameEmail, $sameAmount]);
    }

    public function test_an_invalid_amount_filter_returns_no_rows_instead_of_ignoring_the_filter(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-AMOUNT-1', 'MO-CS-AMOUNT');

        // 填了非法金额时必须返回空集：悄悄忽略条件会让客服照着不相关的结果
        // 回答客户，比明确"查不到"危险得多。
        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters.amount.amount', 'abc')
            ->call('searchOrders')
            ->assertCanNotSeeTableRecords([$order]);
    }

    public function test_searching_without_a_match_clears_previous_results(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-MATCH-1', 'MO-CS-MATCH');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters.order_no.order_no', 'CS-MATCH-1')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$order])
            ->set('tableDeferredFilters.order_no.order_no', 'CS-NOT-FOUND')
            ->call('searchOrders')
            ->assertCountTableRecords(0);
    }

    public function test_clearing_conditions_and_searching_hides_previous_results(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-CLEAR-1', 'MO-CS-CLEAR');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters.order_no.order_no', 'CS-CLEAR-1')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$order])
            ->set('tableDeferredFilters.order_no.order_no', '')
            ->call('searchOrders')
            ->assertCountTableRecords(0)
            ->assertNotified(__('admin.customer_service_order.search.required'));
    }

    public function test_resetting_the_filters_hides_previous_results(): void
    {
        $user = $this->customerServiceUserWithMerchant();
        $order = $this->makeOrder('CS-RESET-1', 'MO-CS-RESET');

        Livewire::actingAs($user)
            ->test(ListCustomerServiceOrders::class)
            ->set('tableDeferredFilters.order_no.order_no', 'CS-RESET-1')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$order])
            ->call('resetTableFiltersForm')
            ->assertCountTableRecords(0)
            ->assertNotNotified();
    }
    // ------------------------------------------------------------------
    // 取消的两项：统计汇总、查看详情
    // ------------------------------------------------------------------

    public function test_it_has_no_summary_statistics(): void
    {
        $user = $this->customerServiceUserWithMerchant();

        // 汇总会在页脚暴露金额口径合计——客服不需要，也不该看到。
        // 直接检查 Table 定义：本模块没有调用任何 ->summarizer()，
        // 所以七列的 summarizers 都应为空数组。
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
            ->set('tableDeferredFilters.order_no.order_no', 'CS-QUERY-1')
            ->call('searchOrders')
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
            ->set('tableDeferredFilters.customer_email.customer_email', 'john@example.com')
            ->call('searchOrders')
            ->assertCanSeeTableRecords([$ownOrder])
            ->assertCanNotSeeTableRecords([$otherOrder]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function userWithDefaultMerchantRole(string $roleName): User
    {
        $this->createMerchantAndApplication();

        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->assignRole(Role::query()
            ->where('merchant_id', $this->merchant->id)
            ->where('name', $roleName)
            ->firstOrFail());

        return $user->fresh();
    }

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

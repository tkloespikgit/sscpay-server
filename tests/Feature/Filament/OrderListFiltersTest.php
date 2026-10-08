<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Application;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\LogisticsImportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 订单列表筛选栏的回归测试：订单号/商户订单号走精准匹配（不是 like），
 * 订单状态与支付方式支持多选。这几条都是纯 Filament 声明式配置，
 * 写错了页面不会报错、只会静默筛错数据，所以用组件测试兜住。
 */
class OrderListFiltersTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();

        $admin = User::create([
            'name' => 'Root',
            'email' => 'root@example.com',
            'password' => bcrypt('secret'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_order_no_and_merchant_order_no_filters_match_exactly(): void
    {
        $target = $this->makeOrder('SSC-1001', 'M-2001');
        // 前缀相同：模糊匹配会把它一起捞出来，精准匹配不会。
        $similar = $this->makeOrder('SSC-100', 'M-200');

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$target, $similar])
            ->filterTable('order_no', ['order_no' => 'SSC-100'])
            ->assertCanSeeTableRecords([$similar])
            ->assertCanNotSeeTableRecords([$target]);

        Livewire::test(ListOrders::class)
            ->filterTable('merchant_order_no', ['merchant_order_no' => 'M-2001'])
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$similar]);
    }

    public function test_order_no_filter_trims_pasted_whitespace(): void
    {
        $target = $this->makeOrder('SSC-1001', 'M-2001');

        Livewire::test(ListOrders::class)
            ->filterTable('order_no', ['order_no' => '  SSC-1001 '])
            ->assertCanSeeTableRecords([$target]);
    }

    public function test_status_filter_accepts_multiple_values(): void
    {
        $paid = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);
        $refunded = $this->makeOrder('SSC-2', 'M-2', ['status' => 'refunded']);
        $pending = $this->makeOrder('SSC-3', 'M-3', ['status' => 'pending']);

        Livewire::test(ListOrders::class)
            ->filterTable('status', ['paid', 'refunded'])
            ->assertCanSeeTableRecords([$paid, $refunded])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_payment_method_filter_accepts_multiple_values(): void
    {
        $alipay = $this->makePaymentMethod('alipay');
        $usdt = $this->makePaymentMethod('usdt');
        $paypal = $this->makePaymentMethod('paypal');

        $a = $this->makeOrder('SSC-1', 'M-1', ['payment_method' => $alipay->method_code, 'payment_method_id' => $alipay->id]);
        $b = $this->makeOrder('SSC-2', 'M-2', ['payment_method' => $usdt->method_code, 'payment_method_id' => $usdt->id]);
        $c = $this->makeOrder('SSC-3', 'M-3', ['payment_method' => $paypal->method_code, 'payment_method_id' => $paypal->id]);

        Livewire::test(ListOrders::class)
            ->filterTable('payment_method', ['alipay', 'usdt'])
            ->assertCanSeeTableRecords([$a, $b])
            ->assertCanNotSeeTableRecords([$c]);
    }

    public function test_deleted_payment_method_remains_available_in_filter(): void
    {
        $method = $this->makePaymentMethod('archived');
        $target = $this->makeOrder('SSC-ARCHIVED', 'M-ARCHIVED', ['payment_method' => $method->method_code, 'payment_method_id' => $method->id]);
        $other = $this->makeOrder('SSC-OTHER', 'M-OTHER');
        $method->delete();

        $page = Livewire::test(ListOrders::class);
        $options = $page->instance()->getTable()->getFilter('payment_method')->getOptions();
        $this->assertSame('ARCHIVED', $options[$this->merchant->name]['archived']);

        $page->filterTable('payment_method', ['archived'])
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_export_without_merchant_filter_downloads_current_results(): void
    {
        $this->makeOrder('SSC-EXPORT', 'M-EXPORT');

        Livewire::test(ListOrders::class)
            ->callAction('exportLogisticsTemplate')
            ->assertFileDownloaded('logistics_template_'.now()->format('Ymd_His').'.csv');
    }

    public function test_export_preserves_filters_and_merchant_permissions(): void
    {
        $otherMerchant = Merchant::create(['name' => 'Other Merchant', 'contact_person' => 'Other', 'contact_phone' => '123456', 'contact_email' => 'other@example.com']);
        $this->makeOrder('SSC-OWN', 'M-OWN', ['status' => 'paid']);
        $this->makeOrder('SSC-PENDING', 'M-PENDING', ['status' => 'pending']);
        $this->makeOrder('SSC-OTHER', 'M-OTHER', ['merchant_id' => $otherMerchant->id, 'status' => 'paid']);

        $service = app(LogisticsImportService::class);
        $page = Livewire::test(ListOrders::class)->filterTable('status', ['paid']);
        $csv = $service->generateTemplate(null, $page->instance()->getFilteredTableQuery());
        $this->assertStringContainsString('SSC-OWN', $csv);
        $this->assertStringContainsString('SSC-OTHER', $csv);
        $this->assertStringNotContainsString('SSC-PENDING', $csv);

        $merchantUser = User::create([
            'name' => 'Merchant User', 'email' => 'export@example.com',
            'password' => bcrypt('secret'), 'merchant_id' => $this->merchant->id,
        ]);
        $this->actingAs($merchantUser);
        $csv = $service->generateTemplate(null, Order::query()->where('status', 'paid'));
        $this->assertStringContainsString('SSC-OWN', $csv);
        $this->assertStringNotContainsString('SSC-OTHER', $csv);
        $this->assertStringNotContainsString('SSC-PENDING', $csv);

        // 显式传入其他商户也不能绕过登录用户的租户范围。
        $csv = $service->generateTemplate($otherMerchant->id, Order::query());
        $this->assertStringNotContainsString('SSC-OTHER', $csv);

        $manager = User::create([
            'name' => 'Manager', 'email' => 'manager-export@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->merchant->update(['owner_id' => $manager->id]);
        $this->actingAs($manager);
        $csv = $service->generateTemplate(null, Order::query()->where('status', 'paid'));
        $this->assertStringContainsString('SSC-OWN', $csv);
        $this->assertStringNotContainsString('SSC-OTHER', $csv);
    }

    /**
     * 全局搜索框要能同时命中 应用名称 / app_id / 订单号 / 商户订单号 / 三方交易号。
     * 关联列（application.name）的 searchable() 字段名写法很容易写错——写成带表名的
     * 'applications.app_id' 会被 Filament 当成 JSON 路径，MySQL 上直接报
     * Unknown column 'applications'（sqlite 不报错，所以这里连搜出来的行也一起断言）。
     */
    public function test_global_search_matches_application_name_and_app_id(): void
    {
        // 名称用单个词：Filament 的全局搜索会按空格拆词再 AND，多词名称容易
        // 被其他列（比如商户名"Test Merchant"里的 Test）顺带命中，断言就不准了。
        $alpha = $this->makeApplication('Alphaco');
        $beta = $this->makeApplication('Betaco');

        $target = $this->makeOrder('SSC-1', 'M-1', ['application_id' => $alpha->id]);
        $other = $this->makeOrder('SSC-2', 'M-2', ['application_id' => $beta->id]);

        foreach ([$alpha->name, $alpha->app_id] as $term) {
            Livewire::test(ListOrders::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    private function makeApplication(string $name): Application
    {
        return Application::createWithCredentials([
            'merchant_id' => $this->merchant->id,
            'name' => $name,
            'website' => 'https://'.strtolower($name).'.example.com',
        ]);
    }

    public function test_global_search_matches_order_numbers(): void
    {
        $target = $this->makeOrder('SSC-1', 'M-1', ['transaction_id' => 'TXN-9']);
        $other = $this->makeOrder('SSC-2', 'M-2');

        foreach (['SSC-1', 'M-1', 'TXN-9'] as $term) {
            Livewire::test(ListOrders::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_application_column_shows_name_without_app_id(): void
    {
        $order = $this->makeOrder('SSC-1', 'M-1');

        // 只断言列本身的呈现值：app_id 仍会出现在“应用”筛选下拉的选项里
        // （平台侧用来区分不同商户的同名应用），那是筛选项不是列。
        Livewire::test(ListOrders::class)
            ->assertTableColumnFormattedStateSet('application.name', $this->application->name, $order);
    }
}

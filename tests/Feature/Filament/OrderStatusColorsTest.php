<?php

namespace Tests\Feature\Filament;

use App\Filament\Observer\Resources\OrderResource\Pages\ListOrders as ObserverListOrders;
use App\Filament\Observer\Resources\OrderResource\Pages\ViewOrder as ObserverViewOrder;
use App\Filament\Resources\CustomerServiceOrderResource\Pages\ListCustomerServiceOrders;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\CheckoutLink;
use App\Models\Observer;
use App\Models\Order;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderStatusColorsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const CUSTOM_COLORS = [
        'pending' => 'info',
        'paid' => 'danger',
        'shipped' => 'primary',
        'completed' => 'gray',
        'cancelled' => 'success',
        'failed' => 'info',
        'expired' => 'warning',
        'disputing' => 'success',
        'dispute_review' => 'info',
        'partially_refunded' => 'primary',
        'refunded' => 'gray',
        'chargeback' => 'warning',
    ];

    public function test_order_list_and_details_use_configured_colors_for_every_order_status(): void
    {
        $this->createMerchantAndApplication();
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Filament::setCurrentPanel('admin');
        SystemConfig::set('order.status_colors', self::CUSTOM_COLORS);
        $orders = $this->createOrdersForStatuses(array_keys(self::CUSTOM_COLORS));

        $page = Livewire::test(ListOrders::class)
            ->set('tableRecordsPerPage', 25)
            ->assertCanSeeTableRecords($orders);
        $column = $page->instance()->getTable()->getColumn('status');

        foreach ($orders as $order) {
            $column->record($order);
            $this->assertSame(self::CUSTOM_COLORS[$order->status], $column->getColor($column->getState()), $order->status);
            $entry = $this->statusEntry(new ViewOrder, $order);
            $this->assertSame(self::CUSTOM_COLORS[$order->status], $entry->getColor($entry->getState()), $order->status);
        }
    }

    public function test_customer_service_results_use_configured_colors_for_every_order_status(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $user->givePermissionTo(Permissions::CUSTOMER_SERVICE_ORDERS_VIEW);
        $this->actingAs($user);
        Filament::setCurrentPanel('merchant');
        SystemConfig::set('order.status_colors', self::CUSTOM_COLORS);
        $orders = $this->createOrdersForStatuses(array_keys(self::CUSTOM_COLORS));

        $page = Livewire::test(ListCustomerServiceOrders::class)
            ->set('tableRecordsPerPage', 25)
            ->set('tableDeferredFilters.customer_email.customer_email', 'john@example.com')
            ->call('searchOrders')
            ->assertCanSeeTableRecords($orders);
        $column = $page->instance()->getTable()->getColumn('status');

        foreach ($orders as $order) {
            $column->record($order);
            $this->assertSame(self::CUSTOM_COLORS[$order->status], $column->getColor($column->getState()), $order->status);
        }
    }

    public function test_observer_list_and_details_keep_grouped_labels_and_use_each_original_status_color(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('observer-color-channel');
        $observer = Observer::create([
            'name' => 'Status color viewer',
            'email' => 'status-colors@example.com',
            'password' => bcrypt('secret'),
            'status' => true,
            'amount_display_ratio' => '100.00',
        ]);
        $observer->paymentMethods()->attach($method);
        $this->actingAs($observer, 'observer');
        Filament::setCurrentPanel('observer');
        SystemConfig::set('order.status_colors', self::CUSTOM_COLORS);
        $groups = [
            'paid' => 'paid',
            'shipped' => 'paid',
            'completed' => 'paid',
            'refunded' => 'refunded',
            'partially_refunded' => 'refunded',
            'chargeback' => 'chargeback',
            'disputing' => 'disputing',
            'dispute_review' => 'disputing',
        ];
        $orders = $this->createOrdersForStatuses(array_keys($groups), $method->id);

        $page = Livewire::test(ObserverListOrders::class)
            ->assertCanSeeTableRecords($orders);
        $column = $page->instance()->getTable()->getColumn('status');

        foreach ($orders as $order) {
            $column->record($order);
            $this->assertSame(self::CUSTOM_COLORS[$order->status], $column->getColor($column->getState()), $order->status);
            $this->assertSame(__('observer.orders.statuses.'.$groups[$order->status]), $column->formatState($column->getState()));
            $entry = $this->statusEntry(new ObserverViewOrder, $order);
            $this->assertSame(self::CUSTOM_COLORS[$order->status], $entry->getColor($entry->getState()), $order->status);
            $this->assertSame(__('observer.orders.statuses.'.$groups[$order->status]), $entry->formatState($entry->getState()));
        }
    }

    public function test_checkout_result_uses_the_configured_status_color_and_still_renders_without_an_order(): void
    {
        $this->withoutVite();
        $this->createMerchantAndApplication();
        SystemConfig::set('order.status_colors', ['paid' => 'danger']);
        $order = $this->makeOrder('CHECKOUT-COLOR', 'M-CHECKOUT-COLOR', ['status' => 'paid']);
        $link = new CheckoutLink(['title' => 'Checkout status colors', 'slug' => 'status-colors']);
        $data = ['link' => $link, 'outcome' => 'success', 'order' => $order];

        $this->view('checkout.result', $data)
            ->assertSee('style="color: '.FilamentColor::getColor('danger')[600].'">paid</dd>', escape: false)
            ->assertSee($order->order_no);
        $this->view('checkout.result', array_replace($data, ['order' => null]))
            ->assertSee($link->title)
            ->assertDontSee($order->order_no);
    }

    /**
     * @param  array<string>  $statuses
     * @return array<Order>
     */
    private function createOrdersForStatuses(array $statuses, ?int $paymentMethodId = null): array
    {
        return array_map(fn (string $status): Order => $this->makeOrder('STATUS-'.$status, 'M-STATUS-'.$status, [
            'status' => $status,
            'payment_method_id' => $paymentMethodId,
        ]), $statuses);
    }

    private function statusEntry(ViewRecord $page, Order $order): TextEntry
    {
        $entry = $page->infolist(Schema::make($page)->record($order))->getComponentByStatePath('status');
        $this->assertInstanceOf(TextEntry::class, $entry);

        return $entry;
    }
}

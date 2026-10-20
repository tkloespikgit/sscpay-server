<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PurchaseOrderItem;
use App\Models\SiteProduct;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_generates_a_local_purchase_order_with_price_snapshots_and_scheduled_times(): void
    {
        $method = $this->paymentMethod();
        $product = $this->products($method, 1, [
            'name' => 'Original product',
            'name_translated' => '翻译后的商品',
            'sku' => 'SNAPSHOT-SKU',
            'price_min' => '0.05',
            'price_max' => '999.00',
        ])->sole();
        $admin = $this->superAdmin();

        $order = app(PurchaseOrderService::class)->generate($method, $admin, [
            'ordered_at' => '2026-10-01 12:00:00',
            'shipping_fee' => '1.23',
            'discount' => '0.10',
        ])->fresh('items');

        $this->assertModelExists($order);
        $this->assertSame($method->id, $order->payment_method_id);
        $this->assertSame($admin->id, $order->created_by);
        $this->assertSame('CNY', $order->currency);
        $this->assertMatchesRegularExpression('/^33\d{17}$/', $order->order_no);
        $this->assertMatchesRegularExpression('/^20261001120000\d{20}$/', $order->transaction_id);
        $this->assertSame('2026-10-01 12:00:00', $order->ordered_at->format('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual(1, $order->paid_at->getTimestamp() - $order->ordered_at->getTimestamp());
        $this->assertLessThanOrEqual(3600, $order->paid_at->getTimestamp() - $order->ordered_at->getTimestamp());
        $this->assertGreaterThanOrEqual(86400, $order->shipped_at->getTimestamp() - $order->paid_at->getTimestamp());
        $this->assertLessThanOrEqual(172800, $order->shipped_at->getTimestamp() - $order->paid_at->getTimestamp());
        $this->assertGreaterThanOrEqual(259200, $order->received_at->getTimestamp() - $order->shipped_at->getTimestamp());
        $this->assertLessThanOrEqual(432000, $order->received_at->getTimestamp() - $order->shipped_at->getTimestamp());

        $item = $order->items->sole();
        $this->assertSame($product->id, $item->site_product_id);
        $this->assertSame('翻译后的商品', $item->product_name);
        $this->assertSame('SNAPSHOT-SKU', $item->sku);
        $this->assertSame('0.05', $item->source_price);
        $this->assertSame('0.11', $item->unit_price);
        $this->assertGreaterThanOrEqual(10, $item->quantity);
        $this->assertLessThanOrEqual(20, $item->quantity);
        $lineCents = $item->quantity * 11;
        $this->assertSame(sprintf('%d.%02d', intdiv($lineCents, 100), $lineCents % 100), $item->subtotal);
        $this->assertSame($item->subtotal, $order->subtotal);
        $this->assertSame('1.23', $order->shipping_fee);
        $this->assertSame('0.10', $order->discount);
        $this->assertSame(bcadd($order->subtotal, '1.13', 2), $order->total);

        $product->update(['name_translated' => '已更改', 'sku' => 'NEW-SKU', 'price_min' => '50.00']);
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $item->id,
            'product_name' => '翻译后的商品',
            'sku' => 'SNAPSHOT-SKU',
            'source_price' => '0.05',
            'unit_price' => '0.11',
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('smallProductCounts')]
    public function test_selects_every_product_when_the_site_has_at_most_thirty(int $count): void
    {
        $method = $this->paymentMethod();
        $products = $this->products($method, $count);

        $order = app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), $this->orderData());

        $this->assertEqualsCanonicalizing($products->pluck('id')->all(), $order->items->pluck('site_product_id')->all());
    }

    public static function smallProductCounts(): array
    {
        return [
            'below thirty' => [29],
            'exactly thirty' => [30],
        ];
    }

    public function test_random_selection_stays_within_the_selected_system_payment_method(): void
    {
        $method = $this->paymentMethod();
        $method->update(['merchant_id' => null]);
        $products = $this->products($method, 35);
        $otherMethod = $this->makePaymentMethod('another-site');
        $otherProduct = $this->products($otherMethod, 1)->sole();

        $order = app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), $this->orderData());

        $selectedIds = $order->items->pluck('site_product_id');
        $this->assertGreaterThanOrEqual(30, $selectedIds->count());
        $this->assertLessThanOrEqual(35, $selectedIds->count());
        $this->assertSame($selectedIds->count(), $selectedIds->unique()->count());
        $this->assertSame([], $selectedIds->diff($products->pluck('id'))->values()->all());
        $this->assertNotContains($otherProduct->id, $selectedIds->all());
    }

    public function test_automatically_calculates_freight_and_discount_from_the_goods_subtotal(): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1, ['price_min' => '100.00']);

        $order = app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), [
            ...$this->orderData(),
            'shipping_fee' => '',
            'discount' => null,
        ])->fresh('items');

        $this->assertSame('Item 1', $order->items->sole()->product_name);
        $this->assertSame('210.00', $order->items->sole()->unit_price);
        $this->assertGreaterThanOrEqual(0, bccomp($order->shipping_fee, bcmul($order->subtotal, '0.03', 2), 2));
        $this->assertLessThanOrEqual(0, bccomp($order->shipping_fee, bcmul($order->subtotal, '0.08', 2), 2));
        $this->assertGreaterThanOrEqual(0, bccomp($order->discount, bcmul($order->subtotal, '0.10', 2), 2));
        $this->assertLessThanOrEqual(0, bccomp($order->discount, bcmul($order->subtotal, '0.20', 2), 2));
        $this->assertSame(bcsub(bcadd($order->subtotal, $order->shipping_fee, 2), $order->discount, 2), $order->total);
    }

    public function test_editing_freight_and_discount_keeps_the_generated_goods_identifiers_and_times(): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1, ['price_min' => '100.00']);
        $admin = $this->superAdmin();
        $order = app(PurchaseOrderService::class)->generate($method, $admin, $this->orderData())->fresh();
        $before = $order->getRawOriginal();
        $beforeItems = $order->items()->get()->toArray();

        $updated = app(PurchaseOrderService::class)->updateCharges($order, $admin, [
            'shipping_fee' => '10.25',
            'discount' => '50.00',
        ])->fresh();

        $this->assertSame('10.25', $updated->shipping_fee);
        $this->assertSame('50.00', $updated->discount);
        $this->assertSame(bcsub($updated->subtotal, '39.75', 2), $updated->total);
        foreach (['shipping_fee', 'discount', 'total', 'updated_at'] as $field) {
            unset($before[$field]);
        }
        $this->assertSame($before, array_intersect_key($updated->getRawOriginal(), $before));
        $this->assertSame($beforeItems, $updated->items()->get()->toArray());
    }

    #[DataProvider('unauthorizedActors')]
    public function test_only_enabled_super_admins_can_generate_orders(bool $superAdmin, bool $enabled): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1);
        $actor = User::factory()->create(['is_super_admin' => $superAdmin, 'status' => $enabled]);

        $this->expectException(AuthorizationException::class);

        try {
            app(PurchaseOrderService::class)->generate($method, $actor, $this->orderData());
        } finally {
            $this->assertDatabaseCount('purchase_orders', 0);
            $this->assertDatabaseCount('purchase_order_items', 0);
        }
    }

    #[DataProvider('unauthorizedActors')]
    public function test_only_enabled_super_admins_can_edit_charges(bool $superAdmin, bool $enabled): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1);
        $order = app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), $this->orderData())->fresh();
        $before = $order->getRawOriginal();
        $actor = User::factory()->create(['is_super_admin' => $superAdmin, 'status' => $enabled]);

        $this->expectException(AuthorizationException::class);

        try {
            app(PurchaseOrderService::class)->updateCharges($order, $actor, ['shipping_fee' => '1.00', 'discount' => '0.00']);
        } finally {
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
    }

    public static function unauthorizedActors(): array
    {
        return [
            'platform manager' => [false, true],
            'disabled super administrator' => [true, false],
        ];
    }

    #[DataProvider('invalidGenerationData')]
    public function test_invalid_generation_input_leaves_no_purchase_order(array $overrides, string $field): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1);

        try {
            app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), [...$this->orderData(), ...$overrides]);
            $this->fail('Invalid purchase order data must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }

    public static function invalidGenerationData(): array
    {
        return [
            'negative freight' => [['shipping_fee' => '-0.01'], 'shipping_fee'],
            'negative discount' => [['discount' => '-0.01'], 'discount'],
            'freight with more than two decimals' => [['shipping_fee' => '1.001'], 'shipping_fee'],
            'discount with more than two decimals' => [['discount' => '1.001'], 'discount'],
            'discount above goods subtotal' => [['discount' => '999999.99'], 'discount'],
            'invalid order time' => [['ordered_at' => 'not-a-date'], 'ordered_at'],
        ];
    }

    public function test_generation_without_source_products_is_rejected_without_writes(): void
    {
        $method = $this->paymentMethod();

        $this->expectException(ValidationException::class);

        try {
            app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), $this->orderData());
        } finally {
            $this->assertDatabaseCount('purchase_orders', 0);
            $this->assertDatabaseCount('purchase_order_items', 0);
        }
    }

    public function test_invalid_discount_edit_preserves_all_saved_order_values(): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1);
        $admin = $this->superAdmin();
        $order = app(PurchaseOrderService::class)->generate($method, $admin, $this->orderData())->fresh();
        $before = $order->getRawOriginal();
        $beforeItems = $order->items()->get()->toArray();

        try {
            app(PurchaseOrderService::class)->updateCharges($order, $admin, ['shipping_fee' => '0.00', 'discount' => '999999.99']);
            $this->fail('A discount above the goods subtotal must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('discount', $exception->errors());
        }

        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
    }

    public function test_a_failed_item_write_rolls_back_the_whole_purchase_order(): void
    {
        $method = $this->paymentMethod();
        $this->products($method, 1);
        PurchaseOrderItem::creating(static function (): void {
            throw new \RuntimeException('Simulated purchase item failure');
        });

        try {
            app(PurchaseOrderService::class)->generate($method, $this->superAdmin(), $this->orderData());
            $this->fail('The item write should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated purchase item failure', $exception->getMessage());
        } finally {
            PurchaseOrderItem::flushEventListeners();
        }

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }

    private function paymentMethod(): PaymentMethod
    {
        $this->createMerchantAndApplication();

        return $this->makePaymentMethod('purchase-site');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return Collection<int, SiteProduct>
     */
    private function products(PaymentMethod $method, int $count, array $overrides = []): Collection
    {
        return collect(range(1, $count))->map(fn (int $index): SiteProduct => $method->siteProducts()->create([
            'merchant_id' => $method->merchant_id,
            'woo_product_id' => $index,
            'name' => 'Item '.$index,
            'price_min' => '1.00',
            'price_max' => '1.00',
            'currency' => 'USD',
            ...$overrides,
        ]));
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'status' => true]);
    }

    /** @return array{ordered_at: string} */
    private function orderData(): array
    {
        return ['ordered_at' => '2026-10-01 12:00:00'];
    }
}

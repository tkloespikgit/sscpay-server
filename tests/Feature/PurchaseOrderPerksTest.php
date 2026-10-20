<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PurchaseOrderPerksTest extends TestCase
{
    use RefreshDatabase;

    private const CANDIDATE_PERKS = [
        '极速退款', '7天无理由退货', '真实工厂', '破损包赔', '品质保障',
        '跨境无忧', '严选晚发必赔', '48小时发货', '退货包运费', '延期必赔',
    ];

    public function test_generating_a_purchase_order_leaves_perks_uninitialized_until_a_page_is_opened(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $paymentMethod = $this->paymentMethodWithProduct();

        $order = app(PurchaseOrderService::class)->generate($paymentMethod, $admin, [
            'ordered_at' => '2026-10-01 12:00:00',
        ])->fresh();

        $this->assertNull($order->perks_words);
        $this->assertNull($order->getRawOriginal('perks_words'));
        Http::assertNothingSent();
    }

    #[TestWith(['purchase-orders.show'])]
    #[TestWith(['purchase-orders.details'])]
    public function test_first_open_persists_four_to_eight_unique_perks_and_all_later_pages_keep_the_same_order(string $firstRoute): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $before = $order->getRawOriginal();
        $beforeItems = $order->items()->get()->toArray();
        $this->travel(5)->minutes();

        $response = $this->actingAs($admin)->get(route($firstRoute, $order->id))->assertOk();

        $initialized = $order->fresh();
        $perks = $initialized->perks_words;
        $this->assertIsArray($perks);
        $this->assertGreaterThanOrEqual(4, count($perks));
        $this->assertLessThanOrEqual(8, count($perks));
        $this->assertSame(count($perks), count(array_unique($perks)));
        $this->assertSame([], array_diff($perks, self::CANDIDATE_PERKS));
        $this->assertSame(range(0, count($perks) - 1), array_keys($perks));
        $response->assertViewHas('perks_words', $perks)->assertSeeInOrder($perks);
        $this->assertNull($before['perks_words']);
        $before['perks_words'] = $initialized->getRawOriginal('perks_words');
        $this->assertSame($before, $initialized->getRawOriginal());

        $this->travel(5)->minutes();
        foreach (['purchase-orders.show', 'purchase-orders.details', 'purchase-orders.show'] as $routeName) {
            $this->get(route($routeName, $order->id))
                ->assertOk()
                ->assertViewHas('perks_words', $perks)
                ->assertSeeInOrder($perks);
            $this->assertSame($initialized->getRawOriginal(), $order->fresh()->getRawOriginal());
        }

        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    #[TestWith([['延期必赔', '极速退款', '品质保障', '48小时发货']])]
    #[TestWith([[]])]
    public function test_saved_perks_and_an_explicit_empty_array_are_reused_without_changes(array $perks): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $order->update(['perks_words' => $perks]);
        $before = $order->fresh()->getRawOriginal();
        $this->travel(5)->minutes();

        $this->assertSame($perks, app(PurchaseOrderService::class)->initializePerksWords($order, $admin));
        $this->actingAs($admin);
        foreach (['purchase-orders.show', 'purchase-orders.details'] as $routeName) {
            $this->get(route($routeName, $order->id))
                ->assertOk()
                ->assertViewHas('perks_words', $perks)
                ->assertSeeInOrder($perks);
        }

        $this->assertSame($before, $order->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_a_second_stale_order_instance_reuses_persisted_perks_without_saving_its_dirty_fields(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $firstInstance = $order->fresh();
        $staleInstance = $order->fresh();
        $staleInstance->subtotal = '999.99';
        $beforeItems = $order->items()->get()->toArray();
        $service = app(PurchaseOrderService::class);
        $firstPerks = $service->initializePerksWords($firstInstance, $admin);
        $afterFirst = $order->fresh()->getRawOriginal();

        $secondPerks = $service->initializePerksWords($staleInstance, $admin);

        $this->assertSame($firstPerks, $secondPerks);
        $this->assertSame($firstPerks, $staleInstance->perks_words);
        $this->assertSame($afterFirst, $order->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        Http::assertNothingSent();
    }

    #[TestWith([false, true, false])]
    #[TestWith([true, false, false])]
    #[TestWith([true, true, true])]
    public function test_only_enabled_super_admins_can_initialize_perks(bool $superAdmin, bool $enabled, bool $deleted): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());
        $before = $order->getRawOriginal();
        $actor = User::factory()->create(['is_super_admin' => $superAdmin, 'status' => $enabled]);
        if ($deleted) {
            $actor->delete();
        }
        $this->expectException(AuthorizationException::class);

        try {
            app(PurchaseOrderService::class)->initializePerksWords($order, $actor);
        } finally {
            $this->assertSame($before, $order->fresh()->getRawOriginal());
            $this->assertNull($order->fresh()->perks_words);
            Http::assertNothingSent();
        }
    }

    public function test_saved_perks_still_require_service_authorization(): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());
        $order->update(['perks_words' => ['极速退款', '品质保障']]);
        $before = $order->fresh()->getRawOriginal();
        $actor = User::factory()->create(['is_super_admin' => false, 'status' => true]);
        $this->expectException(AuthorizationException::class);

        try {
            app(PurchaseOrderService::class)->initializePerksWords($order, $actor);
        } finally {
            $this->assertSame($before, $order->fresh()->getRawOriginal());
            Http::assertNothingSent();
        }
    }

    public function test_unauthorized_page_requests_do_not_initialize_perks(): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());
        $before = $order->getRawOriginal();

        foreach (['purchase-orders.show', 'purchase-orders.details'] as $routeName) {
            $this->get(route($routeName, $order->id))->assertForbidden();
        }

        $manager = User::factory()->create(['is_super_admin' => false, 'status' => true]);
        $this->actingAs($manager);
        foreach (['purchase-orders.show', 'purchase-orders.details'] as $routeName) {
            $this->get(route($routeName, $order->id))->assertForbidden();
        }

        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertNull($order->fresh()->perks_words);
        Http::assertNothingSent();
    }

    public function test_a_failure_after_the_perks_write_rolls_back_the_order_and_allows_retry(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $before = $order->getRawOriginal();
        $beforeItems = $order->items()->get()->toArray();
        PurchaseOrder::updated(static function (): void {
            throw new RuntimeException('Simulated perks persistence failure');
        });

        try {
            app(PurchaseOrderService::class)->initializePerksWords($order, $admin);
            $this->fail('The perks write must fail when its updated listener fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated perks persistence failure', $exception->getMessage());
        } finally {
            PurchaseOrder::flushEventListeners();
        }

        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertNull($order->perks_words);
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $perks = app(PurchaseOrderService::class)->initializePerksWords($order, $admin);
        $this->assertGreaterThanOrEqual(4, count($perks));
        $this->assertSame($perks, $order->fresh()->perks_words);
        Http::assertNothingSent();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'status' => true]);
    }

    private function paymentMethodWithProduct(): PaymentMethod
    {
        $paymentMethod = PaymentMethod::create([
            'merchant_id' => null,
            'method_code' => 'purchase_perks',
            'method_name' => 'Purchase Perks Site',
            'domain' => 'https://purchase.example.com',
            'is_active' => true,
        ]);
        $paymentMethod->siteProducts()->create([
            'woo_product_id' => 1,
            'name' => 'Perks source product',
            'price_min' => '10.00',
            'price_max' => '10.00',
        ]);

        return $paymentMethod;
    }

    private function purchaseOrder(User $creator): PurchaseOrder
    {
        $paymentMethod = $this->paymentMethodWithProduct();
        $order = $paymentMethod->purchaseOrders()->create([
            'created_by' => $creator->id,
            'order_no' => '3398765432109876543',
            'transaction_id' => '2026100112000022001827581430584518',
            'currency' => 'CNY',
            'ordered_at' => '2026-10-01 12:00:00',
            'paid_at' => '2026-10-01 12:38:20',
            'shipped_at' => '2026-10-03 08:22:10',
            'received_at' => '2026-10-07 17:05:45',
            'subtotal' => '210.00',
            'shipping_fee' => '10.00',
            'discount' => '20.00',
            'total' => '200.00',
        ]);
        $order->items()->create([
            'site_product_id' => $paymentMethod->siteProducts()->sole()->id,
            'product_name' => 'Saved perks product',
            'source_price' => '10.00',
            'unit_price' => '21.00',
            'quantity' => 10,
            'subtotal' => '210.00',
        ]);

        return $order->fresh();
    }
}

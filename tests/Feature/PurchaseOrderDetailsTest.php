<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PurchaseOrderDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_super_admin_can_read_the_purchase_details_with_real_profiles_items_amounts_and_times(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $before = $order->getRawOriginal();
        $beforeItems = $order->items()->get()->toArray();

        $this->actingAs($admin)
            ->get(route('purchase-orders.details', ['purchaseOrder' => $order->id]))
            ->assertOk()
            ->assertViewIs('purchase-order-details')
            ->assertViewHas('purchaseOrder', fn (PurchaseOrder $document): bool => $document->is($order)
                && $document->items->pluck('id')->all() === $order->items->pluck('id')->all())
            ->assertViewHas('detailFields', [
                'buyerContactName' => '采购法人',
                'buyerCompany' => '深圳采购有限公司',
                'buyerAddress' => '广东省深圳市仓库路88号',
                'buyerPhone' => '13800001111',
                'buyerTelephone' => '未设置',
                'buyerEmail' => 'buyer@example.com',
                'sellerMemberName' => '广州供货有限公司',
                'sellerContactName' => '供货联系人',
                'sellerPhone' => '13900002222',
                'sellerTelephone' => '未设置',
                'sellerAddress' => '广东省广州市供货路66号',
                'sellerEmail' => 'supplier@example.com',
                'shippingCompany' => '未设置',
                'trackingNumber' => '未设置',
                'paymentMethodName' => '采购渠道',
            ])
            ->assertSee([
                '3398765432109876543', '2026100112000022001827581430584518',
                '2026-10-01 12:00:00', '2026-10-01 12:38:20', '2026-10-03 08:22:10', '2026-10-07 17:05:45',
                '采购法人', '深圳采购有限公司', '广东省深圳市仓库路88号', '13800001111', 'buyer@example.com',
                '广州供货有限公司', '供货联系人', '13900002222', '广东省广州市供货路66号', 'supplier@example.com',
                '采购快照商品一', '采购快照商品二', 'SNAPSHOT-SKU-17', 'SNAPSHOT-SKU-12',
                '12.34', '25.91', '440.47', '5.00', '10.50', '126.00',
                '566.47', '18.23', '41.25', '543.45', '已确认收货', '人民币（CNY）',
            ])
            ->assertSee('<td>17</td>', false)
            ->assertSee('<td>12</td>', false)
            ->assertDontSee(['S$', '17292', '不应展示的备用仓库', '不应优先展示的公司地址']);

        $initialized = $order->fresh();
        $this->assertNull($before['perks_words']);
        $this->assertIsArray($initialized->perks_words);
        $before['perks_words'] = $initialized->getRawOriginal('perks_words');
        $this->assertSame($before, $initialized->getRawOriginal());
        $this->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertViewHas('perks_words', $initialized->perks_words);
        $this->assertSame($initialized->getRawOriginal(), $order->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_guests_cannot_read_existing_or_unknown_purchase_details(): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());

        $this->get(route('purchase-orders.details', $order->id))->assertForbidden();
        $this->get(route('purchase-orders.details', $order->id + 1))->assertForbidden();

        Http::assertNothingSent();
    }

    #[TestWith([false, true, false])]
    #[TestWith([true, false, false])]
    #[TestWith([true, true, true])]
    public function test_managers_disabled_and_deleted_super_admins_cannot_read_existing_or_unknown_purchase_details(
        bool $superAdmin,
        bool $enabled,
        bool $deleted,
    ): void {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());
        $actor = User::factory()->create(['is_super_admin' => $superAdmin, 'status' => $enabled]);
        if ($deleted) {
            $actor->delete();
        }

        $this->actingAs($actor)->get(route('purchase-orders.details', $order->id))->assertForbidden();
        $this->get(route('purchase-orders.details', $order->id + 1))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_merchant_cannot_read_purchase_details_for_their_own_payment_method(): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());
        $this->seed(PermissionSeeder::class);
        $merchant = Merchant::create([
            'name' => 'Purchase Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'purchase@example.com',
        ]);
        $order->paymentMethod->update(['merchant_id' => $merchant->id]);
        $actor = User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);

        $this->actingAs($actor)->get(route('purchase-orders.details', $order->id))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_super_admin_receives_not_found_for_unknown_purchase_details(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->superAdmin())->get(route('purchase-orders.details', 999))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_details_escape_free_text_profiles_and_item_snapshots(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $profileValues = [];
        foreach ([
            'legal_representative_name', 'company_name', 'legal_representative_phone', 'account_email',
            'supplier_name', 'supplier_contact_person', 'supplier_phone', 'supplier_address', 'supplier_email',
        ] as $field) {
            $profileValues[$field] = '<script>alert("'.$field.'")</script>';
        }
        $address = '<img src=x onerror=alert("address")>';
        $order->paymentMethod->profile->update([...$profileValues, 'stock_addresses' => [$address]]);
        $productName = '<svg onload=alert("product")>';
        $sku = '<img src=x onerror=alert("sku")>';
        $order->items->first()->update(['product_name' => $productName, 'sku' => $sku]);

        $values = [...array_values($profileValues), $address, $productName, $sku];
        $this->actingAs($admin)
            ->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertSee($values)
            ->assertDontSee($values, false);

        Http::assertNothingSent();
    }

    public function test_details_use_safe_product_images_and_fall_back_for_unsafe_or_deleted_sources(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $safeUrl = 'https://images.example.com/purchase.jpg?size=small&v=1';
        $unsafeUrl = 'javascript:alert("unsafe-image")';
        $firstItem = $order->items->first();
        $secondItem = $order->items->last();
        $firstItem->siteProduct->update(['image_url' => $safeUrl]);
        $secondItem->siteProduct->update(['image_url' => $unsafeUrl]);

        $this->actingAs($admin)
            ->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertViewHas('itemImageUrls', [$firstItem->id => $safeUrl, $secondItem->id => null])
            ->assertSee('src="'.e($safeUrl).'"', false)
            ->assertSee('暂无图片')
            ->assertDontSee($unsafeUrl, false);

        $firstItem->siteProduct->delete();

        $this->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertViewHas('itemImageUrls', [$firstItem->id => null, $secondItem->id => null])
            ->assertSee(['采购快照商品一', '25.91', '暂无图片'])
            ->assertDontSee($safeUrl, false);

        Http::assertNothingSent();
    }

    public function test_details_keep_saved_product_names_skus_prices_and_quantities_after_live_product_changes(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $order->items->first()->siteProduct->update([
            'name' => '最新商品名称', 'sku' => 'LIVE-CHANGED-SKU', 'price_min' => '999.99',
        ]);

        $this->actingAs($admin)
            ->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertSee(['采购快照商品一', 'SNAPSHOT-SKU-17', '12.34', '25.91', '440.47'])
            ->assertDontSee(['最新商品名称', 'LIVE-CHANGED-SKU', '999.99']);

        Http::assertNothingSent();
    }

    public function test_details_refresh_shows_edited_freight_discount_and_total_without_changing_other_order_data(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $beforeItems = $order->items()->get()->toArray();
        $this->actingAs($admin)->get(route('purchase-orders.details', $order->id))->assertOk()->assertSee('543.45');
        $before = $order->fresh()->getRawOriginal();

        app(PurchaseOrderService::class)->updateCharges($order, $admin, ['shipping_fee' => '28.12', 'discount' => '11.07']);

        $this->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertSee(['28.12', '11.07', '583.52'])
            ->assertDontSee('543.45');

        foreach (['shipping_fee', 'discount', 'total', 'updated_at'] as $field) {
            unset($before[$field]);
        }
        $this->assertSame($before, array_intersect_key($order->fresh()->getRawOriginal(), $before));
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_details_use_company_address_when_stock_addresses_have_no_nonempty_string(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $order->paymentMethod->profile->update([
            'stock_addresses' => ['', '  ', null, ['address' => 'invalid nested address']],
            'company_address' => '公司备用收货地址',
        ]);

        $this->actingAs($admin)
            ->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertViewHas('detailFields', fn (array $fields): bool => $fields['buyerAddress'] === '公司备用收货地址')
            ->assertSee('公司备用收货地址')
            ->assertDontSee('invalid nested address');

        Http::assertNothingSent();
    }

    public function test_details_show_unset_values_when_profile_is_missing_and_still_load_an_archived_payment_method(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $order->paymentMethod->profile->delete();
        $order->paymentMethod->delete();

        $this->actingAs($admin)
            ->get(route('purchase-orders.details', $order->id))
            ->assertOk()
            ->assertViewHas('detailFields', fn (array $fields): bool => $fields['buyerAddress'] === '未设置'
                && $fields['buyerContactName'] === '未设置'
                && $fields['sellerContactName'] === '未设置'
                && $fields['shippingCompany'] === '未设置'
                && $fields['trackingNumber'] === '未设置'
                && $fields['paymentMethodName'] === '采购渠道')
            ->assertSee(['3398765432109876543', '采购快照商品一', '543.45', '未设置']);

        Http::assertNothingSent();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'status' => true]);
    }

    private function purchaseOrder(User $creator): PurchaseOrder
    {
        $paymentMethod = PaymentMethod::create([
            'merchant_id' => null, 'method_code' => 'purchase_details', 'method_name' => '采购渠道',
            'domain' => 'https://purchase.example.com', 'is_active' => true,
        ]);
        $paymentMethod->profile()->create([
            'legal_representative_name' => '采购法人',
            'company_name' => '深圳采购有限公司',
            'stock_addresses' => ['', '广东省深圳市仓库路88号', '不应展示的备用仓库'],
            'company_address' => '不应优先展示的公司地址',
            'legal_representative_phone' => '13800001111',
            'account_email' => 'buyer@example.com',
            'supplier_name' => '广州供货有限公司',
            'supplier_contact_person' => '供货联系人',
            'supplier_phone' => '13900002222',
            'supplier_address' => '广东省广州市供货路66号',
            'supplier_email' => 'supplier@example.com',
        ]);
        $order = $paymentMethod->purchaseOrders()->create([
            'created_by' => $creator->id, 'order_no' => '3398765432109876543',
            'transaction_id' => '2026100112000022001827581430584518', 'currency' => 'CNY',
            'ordered_at' => '2026-10-01 12:00:00', 'paid_at' => '2026-10-01 12:38:20',
            'shipped_at' => '2026-10-03 08:22:10', 'received_at' => '2026-10-07 17:05:45',
            'subtotal' => '566.47', 'shipping_fee' => '18.23', 'discount' => '41.25', 'total' => '543.45',
        ]);
        foreach ([
            ['采购快照商品一', 'SNAPSHOT-SKU-17', '12.34', '25.91', 17, '440.47'],
            ['采购快照商品二', 'SNAPSHOT-SKU-12', '5.00', '10.50', 12, '126.00'],
        ] as $index => [$name, $sku, $sourcePrice, $unitPrice, $quantity, $subtotal]) {
            $product = $paymentMethod->siteProducts()->create([
                'woo_product_id' => $index + 1, 'name' => $name, 'sku' => $sku,
                'price_min' => $sourcePrice, 'price_max' => $sourcePrice,
            ]);
            $order->items()->create([
                'site_product_id' => $product->id, 'product_name' => $name, 'sku' => $sku,
                'source_price' => $sourcePrice, 'unit_price' => $unitPrice, 'quantity' => $quantity, 'subtotal' => $subtotal,
            ]);
        }

        return $order->fresh(['items.siteProduct', 'paymentMethod.profile']);
    }
}

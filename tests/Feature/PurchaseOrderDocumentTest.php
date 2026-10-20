<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentMethodResource;
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

class PurchaseOrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_super_admin_can_view_the_saved_purchase_order_as_a_procurement_document(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin(['email' => User::emailForAccount('buyer-admin')]);
        $order = $this->purchaseOrder($admin);
        $order->paymentMethod->profile()->create([
            'supplier_name' => '供货公司',
            'supplier_contact_person' => '供货联系人',
            'company_name' => '采购公司',
            'legal_representative_name' => '采购联系人',
        ]);
        $before = $order->getRawOriginal();
        $beforeItems = $order->items()->get()->toArray();

        $this->actingAs($admin)
            ->get(route('purchase-orders.show', ['purchaseOrder' => $order->id]))
            ->assertOk()
            ->assertViewIs('purchase-orders.show')
            ->assertViewHas('purchaseOrder', fn (PurchaseOrder $document): bool => $document->is($order)
                && $document->items->sole()->id === $order->items->sole()->id)
            ->assertViewHas('sellerName', '供货联系人')
            ->assertViewHas('sellerCompany', '供货公司')
            ->assertViewHas('buyerName', '采购联系人')
            ->assertViewHas('buyerAccount', 'buyer-admin')
            ->assertViewHas('documentStatus', '已生成进货单')
            ->assertSee('href="'.e(route('purchase-orders.details', ['purchaseOrder' => $order->id])).'"', false)
            ->assertSee([
                '3398765432109876543', '2026-10-01 12:00:00',
                '采购快照商品', '¥ 25.91', '440.47', '18.23', '417.45',
                '供货公司', '供货联系人', '采购联系人',
            ])
            ->assertDontSee(['S$', '17292', User::ACCOUNT_EMAIL_DOMAIN]);

        $initialized = $order->fresh();
        $this->assertNull($before['perks_words']);
        $this->assertIsArray($initialized->perks_words);
        $before['perks_words'] = $initialized->getRawOriginal('perks_words');
        $this->assertSame($before, $initialized->getRawOriginal());
        $this->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertViewHas('perks_words', $initialized->perks_words);
        $this->assertSame($initialized->getRawOriginal(), $order->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_guests_cannot_view_purchase_orders_or_probe_unknown_identifiers(): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());

        $this->get(route('purchase-orders.show', $order->id))->assertForbidden();
        $this->get(route('purchase-orders.show', $order->id + 1))->assertForbidden();

        Http::assertNothingSent();
    }

    #[TestWith([false, true, false])]
    #[TestWith([true, false, false])]
    #[TestWith([true, true, true])]
    public function test_manager_disabled_super_admin_and_deleted_super_admin_cannot_view_purchase_orders(
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

        $this->actingAs($actor)->get(route('purchase-orders.show', $order->id))->assertForbidden();
        $this->get(route('purchase-orders.show', $order->id + 1))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_merchant_user_cannot_view_a_purchase_order_for_their_payment_method(): void
    {
        Http::preventStrayRequests();
        $order = $this->purchaseOrder($this->superAdmin());
        $this->seed(PermissionSeeder::class);
        $merchant = Merchant::create([
            'name' => 'Purchase Merchant', 'contact_person' => 'Tester',
            'contact_phone' => '123456', 'contact_email' => 'purchase@example.com',
        ]);
        $order->paymentMethod->update(['merchant_id' => $merchant->id]);
        $actor = User::factory()->create(['merchant_id' => $merchant->id, 'is_super_admin' => false, 'status' => true]);

        $this->actingAs($actor)->get(route('purchase-orders.show', $order->id))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_super_admin_receives_not_found_for_a_missing_purchase_order(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->superAdmin())->get(route('purchase-orders.show', 999))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_document_escapes_product_supplier_company_and_buyer_contact_values(): void
    {
        Http::preventStrayRequests();
        $account = '<img src=x onerror=alert("account")>';
        $creator = $this->superAdmin(['email' => User::emailForAccount($account)]);
        $order = $this->purchaseOrder($creator);
        $name = '<script>alert("product")</script>';
        $sku = '<img src=x onerror=alert("sku")>';
        $supplier = '<script>alert("supplier")</script>';
        $company = '<svg onload=alert("company")>';
        $buyer = '<script>alert("buyer")</script>';
        $order->items->sole()->update(['product_name' => $name, 'sku' => $sku]);
        $order->paymentMethod->profile()->create([
            'supplier_name' => $company,
            'supplier_contact_person' => $supplier,
            'legal_representative_name' => $buyer,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertSee([$name, $supplier, $company, $buyer])
            ->assertDontSee([$name, $sku, $supplier, $company, $account, $buyer], false);

        Http::assertNothingSent();
    }

    public function test_document_uses_safe_source_images_and_placeholders_for_missing_or_unsafe_images(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $safeUrl = 'https://images.example.com/purchase.jpg?size=small&v=1';
        $order->items->sole()->siteProduct->update(['image_url' => $safeUrl]);
        $expectedUrls = [$order->items->sole()->id => $safeUrl];
        $imageUrls = [
            'http://images.example.com/another.jpg',
            null,
            'javascript:alert("image")',
            'data:image/svg+xml,<svg onload=alert("image")>',
            'file:///private/image.jpg',
            'https://user:secret@images.example.com/private.jpg',
            '//images.example.com/protocol-relative.jpg',
        ];
        foreach ($imageUrls as $index => $imageUrl) {
            $product = $order->paymentMethod->siteProducts()->create([
                'woo_product_id' => $index + 2,
                'name' => 'Source image '.($index + 2),
                'price_min' => '12.34', 'price_max' => '12.34', 'image_url' => $imageUrl,
            ]);
            $item = $order->items()->create([
                'site_product_id' => $product->id, 'product_name' => 'Image snapshot '.($index + 2),
                'source_price' => '12.34', 'unit_price' => '25.91', 'quantity' => 17, 'subtotal' => '440.47',
            ]);
            $expectedUrls[$item->id] = $index === 0 ? $imageUrl : null;
        }

        $response = $this->actingAs($admin)
            ->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertViewHas('itemImageUrls', $expectedUrls)
            ->assertSee('src="'.e($safeUrl).'"', false)
            ->assertSee('src="http://images.example.com/another.jpg"', false)
            ->assertSee('暂无图片');
        foreach (array_slice($imageUrls, 2) as $imageUrl) {
            $response->assertDontSee($imageUrl, false);
        }

        Http::assertNothingSent();
    }

    public function test_document_keeps_item_snapshots_after_source_product_changes_or_deletion(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $product = $order->items->sole()->siteProduct;
        $product->update(['name' => '商品已改名', 'sku' => 'CHANGED-LIVE-SKU', 'price_min' => '999.99']);

        $this->actingAs($admin)
            ->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertSee(['采购快照商品', '25.91', '440.47'])
            ->assertDontSee(['商品已改名', 'CHANGED-LIVE-SKU', '999.99']);

        $product->delete();

        $this->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertSee(['采购快照商品', '25.91', '440.47', '暂无图片']);

        Http::assertNothingSent();
    }

    public function test_document_refresh_shows_edited_charges_without_regenerating_the_order(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $beforeItems = $order->items()->get()->toArray();
        $this->actingAs($admin)->get(route('purchase-orders.show', $order->id))->assertOk()->assertSee('417.45');
        $before = $order->fresh()->getRawOriginal();

        app(PurchaseOrderService::class)->updateCharges($order, $admin, ['shipping_fee' => '28.12', 'discount' => '11.07']);

        $this->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertViewHas('purchaseOrder', fn (PurchaseOrder $document): bool => $document->discount === '11.07')
            ->assertSee(['28.12', '457.52'])
            ->assertDontSee('417.45');

        foreach (['shipping_fee', 'discount', 'total', 'updated_at'] as $field) {
            unset($before[$field]);
        }
        $this->assertSame($before, array_intersect_key($order->fresh()->getRawOriginal(), $before));
        $this->assertSame($beforeItems, $order->items()->get()->toArray());
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_document_uses_payment_method_name_when_supplier_and_company_are_not_configured(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);

        $this->actingAs($admin)
            ->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertViewHas('sellerName', 'Purchase Site')
            ->assertViewHas('buyerName', '未设置采购方')
            ->assertSee(['Purchase Site', '未设置采购方']);

        Http::assertNothingSent();
    }

    public function test_document_remains_viewable_after_payment_method_deletion_and_links_back_to_the_payment_method_list(): void
    {
        Http::preventStrayRequests();
        $admin = $this->superAdmin();
        $order = $this->purchaseOrder($admin);
        $order->paymentMethod->delete();
        $returnUrl = PaymentMethodResource::getUrl('index', panel: 'admin');

        $this->actingAs($admin)
            ->get(route('purchase-orders.show', $order->id))
            ->assertOk()
            ->assertViewHas('paymentMethodUrl', $returnUrl)
            ->assertViewHas('sellerName', 'Purchase Site')
            ->assertSee('href="'.e($returnUrl).'"', false)
            ->assertSee(['3398765432109876543', '采购快照商品', '417.45']);

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $attributes */
    private function superAdmin(array $attributes = []): User
    {
        return User::factory()->create(['is_super_admin' => true, 'status' => true, ...$attributes]);
    }

    private function purchaseOrder(User $creator): PurchaseOrder
    {
        $paymentMethod = PaymentMethod::create([
            'merchant_id' => null, 'method_code' => 'purchase_document', 'method_name' => 'Purchase Site',
            'domain' => 'https://purchase.example.com', 'is_active' => true,
        ]);
        $product = $paymentMethod->siteProducts()->create([
            'woo_product_id' => 1, 'name' => '采购快照商品', 'sku' => 'SNAPSHOT-SKU-17',
            'price_min' => '12.34', 'price_max' => '12.34',
        ]);
        $order = $paymentMethod->purchaseOrders()->create([
            'created_by' => $creator->id, 'order_no' => '3398765432109876543',
            'transaction_id' => '2026100112000022001827581430584518', 'currency' => 'CNY',
            'ordered_at' => '2026-10-01 12:00:00', 'paid_at' => '2026-10-01 12:38:20',
            'shipped_at' => '2026-10-03 08:22:10', 'received_at' => '2026-10-07 17:05:45',
            'subtotal' => '440.47', 'shipping_fee' => '18.23', 'discount' => '41.25', 'total' => '417.45',
        ]);
        $order->items()->create([
            'site_product_id' => $product->id, 'product_name' => '采购快照商品', 'sku' => 'SNAPSHOT-SKU-17',
            'source_price' => '12.34', 'unit_price' => '25.91', 'quantity' => 17, 'subtotal' => '440.47',
        ]);

        return $order->fresh(['items.siteProduct', 'paymentMethod']);
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CheckoutLinkResource\Pages\CreateCheckoutLink;
use App\Models\CheckoutLink;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 收款链接后台表单的金额一致性校验（需求 3）。
 *
 * 这是第一道闸门：配了商品/运费/税费就必须是固定金额，且各项加起来要和
 * 固定金额严格相等。放过去的话，客户在落地页下单时会被 OrderCreationService
 * 以 AMOUNT_MISMATCH / ITEMS_SUBTOTAL_MISMATCH 拒单——商户直到有客户来投诉
 * "付不了款"才会发现链接配错了，所以这一层必须在保存时就拦住。
 */
class CheckoutLinkFormTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private PaymentGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();

        $this->group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'group_default',
            'group_name' => 'Default Group',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel('merchant');
        $this->actingAs($this->merchantUser());
    }

    public function test_items_that_add_up_to_the_fixed_amount_are_saved(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData([
                'fixed_amount' => '115.00',
                'shipping_fee' => '10.00',
                'tax' => '5.00',
                'items' => [
                    ['product_name' => 'TV Box', 'unit_price' => '50.00', 'quantity' => 2],
                ],
            ]))
            ->call('create');

        $link = CheckoutLink::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('115.00', $link->fixed_amount);
        $this->assertCount(1, $link->items);
        // slug 是保存时生成的，必须足够长才猜不出来（落地页没有其他鉴权）。
        $this->assertSame(24, strlen($link->slug));
    }

    public function test_merchant_can_save_own_google_maps_key(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData(['google_maps_browser_key' => 'merchant-test-key']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('merchant-test-key', CheckoutLink::withoutGlobalScopes()->firstOrFail()->google_maps_browser_key);
    }

    public function test_merchant_can_limit_supported_countries(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData(['supported_countries' => ['DE', 'FR']]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['DE', 'FR'], CheckoutLink::withoutGlobalScopes()->firstOrFail()->supported_countries);
    }

    public function test_merchant_can_add_a_customer_notice(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData(['customer_notice' => "Please check your address.\nWe ship on weekdays."]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            "Please check your address.\nWe ship on weekdays.",
            CheckoutLink::withoutGlobalScopes()->firstOrFail()->customer_notice
        );
    }

    public function test_items_that_do_not_add_up_are_rejected(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData([
                // 商品合计 100 + 运费 10 = 110，和填的 999 对不上。
                'fixed_amount' => '999.00',
                'shipping_fee' => '10.00',
                'items' => [
                    ['product_name' => 'TV Box', 'unit_price' => '50.00', 'quantity' => 2],
                ],
            ]))
            // 断言字段级校验全过——否则"没建出来"可能只是某个字段填错了，
            // 而不是金额一致性规则真的生效了（这个假阳性实际发生过）。
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, CheckoutLink::withoutGlobalScopes()->count());
    }

    public function test_range_mode_is_rejected_once_items_exist(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData([
                'amount_mode' => CheckoutLink::MODE_RANGE,
                'fixed_amount' => null,
                'min_amount' => '10.00',
                'max_amount' => '500.00',
                'items' => [
                    ['product_name' => 'TV Box', 'unit_price' => '50.00', 'quantity' => 2],
                ],
            ]))
            // 断言字段级校验全过——否则"没建出来"可能只是某个字段填错了，
            // 而不是金额一致性规则真的生效了（这个假阳性实际发生过）。
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, CheckoutLink::withoutGlobalScopes()->count());
    }

    public function test_range_mode_without_items_or_fees_is_allowed(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData([
                'amount_mode' => CheckoutLink::MODE_RANGE,
                'fixed_amount' => null,
                'min_amount' => '10.00',
                'max_amount' => '500.00',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $link = CheckoutLink::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(CheckoutLink::MODE_RANGE, $link->amount_mode);
    }

    /**
     * 只配了运费、没配商品，同样触发"必须固定金额"的约束——
     * 运费本身就是一笔要加进总额的钱，客户自己输金额就无从核对。
     */
    public function test_shipping_fee_alone_forces_fixed_amount(): void
    {
        Livewire::test(CreateCheckoutLink::class)
            ->fillForm($this->formData([
                'amount_mode' => CheckoutLink::MODE_RANGE,
                'fixed_amount' => null,
                'min_amount' => '10.00',
                'max_amount' => '500.00',
                'shipping_fee' => '10.00',
            ]))
            // 断言字段级校验全过——否则"没建出来"可能只是某个字段填错了，
            // 而不是金额一致性规则真的生效了（这个假阳性实际发生过）。
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, CheckoutLink::withoutGlobalScopes()->count());
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'merchant_id' => $this->merchant->id,
            'application_id' => $this->application->id,
            'payment_group_id' => $this->group->id,
            'title' => 'TV box - model 209343 checkout',
            'currency' => 'EUR',
            'amount_mode' => CheckoutLink::MODE_FIXED,
            'fixed_amount' => '100.00',
            'shipping_fee' => '0.00',
            'tax' => '0.00',
            'discount' => '0.00',
            'is_active' => true,
            'items' => [],
        ], $overrides);
    }

    private function merchantUser(): User
    {
        $user = User::create([
            'name' => 'Merchant User',
            'email' => 'merchant-user@example.com',
            'password' => bcrypt('secret'),
            'merchant_id' => $this->merchant->id,
        ]);

        $user->givePermissionTo(Permissions::CHECKOUT_LINKS_MANAGE);

        return $user->fresh();
    }
}

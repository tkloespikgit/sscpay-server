<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CryptoPaymentReviewResource;
use App\Filament\Resources\CryptoPaymentReviewResource\Pages\ManageCryptoPaymentReviews;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Support\CryptoOrderDisplay;
use App\Models\CryptoAddress;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoLedgerEntry;
use App\Models\CryptoPaymentRequest;
use App\Models\CryptoPaymentResolution;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderDailyStat;
use App\Services\CryptoOrderStatisticsService;
use App\Services\CryptoPaymentEvidenceService;
use App\Services\OrderStatsAggregator;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesCryptoFinance;
use Tests\TestCase;

class CryptoOrderPresentationTest extends TestCase
{
    use CreatesCryptoFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCryptoReview();
        $this->reviewer->givePermissionTo(Permissions::ORDERS_VIEW);
        $this->requester->givePermissionTo(Permissions::ORDERS_VIEW);
        Filament::setCurrentPanel('admin');
        app()->setLocale('zh_CN');
        $this->actingAs($this->reviewer);
        Filament::bootCurrentPanel();
        Queue::fake();
    }

    public function test_order_list_and_detail_display_exact_crypto_net_and_frozen_rates(): void
    {
        Livewire::test(ListOrders::class)->assertOk()->assertSee('0.040392000000000000 ETH')
            ->assertSee('ETH / Ethereum Sepolia')->assertSee(__('crypto.order.summary'))->assertSee('0.000408000000000000 ETH');
        Livewire::test(ViewOrder::class, ['record' => $this->creditedRequest->order_id])->assertOk()
            ->assertSee('0.040392000000000000 ETH')->assertSee('1 USD = 1 USD; 1 ETH = 2500 USD')
            ->assertSee(__('crypto.order.markup', ['rate' => '2']));
    }

    public function test_uncredited_order_does_not_display_zero_usd_as_crypto_income(): void
    {
        $request = $this->newReviewPayment('20000000000000000');
        $this->assertSame(__('crypto.order.uncredited'), CryptoOrderDisplay::amount($request->order));
        Livewire::test(ListOrders::class)->assertOk()->assertSee(__('crypto.order.uncredited'));
    }

    public function test_reviewer_action_credits_actual_amount_and_exposes_immutable_audit(): void
    {
        $request = $this->newReviewPayment('20000000000000000', true);
        $code = $this->cryptoCode($this->reviewer);
        $this->refreshCryptoHistory();
        $hash = app(CryptoPaymentEvidenceService::class)->capture($request->order)['hash'];
        Livewire::test(ManageCryptoPaymentReviews::class)->assertOk()->assertCanSeeTableRecords([$request->order])
            ->callAction(TestAction::make('reconcile')->table($request->order), [
                'request_key' => (string) Str::uuid(), 'evidence_hash' => $hash, 'reason' => '<script>review</script>',
                'acknowledge' => true, 'mfa_code' => $code,
            ])->assertHasNoActionErrors()->assertNotified();
        $this->assertNotNull($request->order->fresh()->paid_at);
        $this->assertSame('0.019800000000000000 ETH', CryptoOrderDisplay::amount($request->order->fresh()));
        Livewire::test(ManageCryptoPaymentReviews::class)->mountAction(TestAction::make('view')->table($request->order))
            ->assertMountedActionModalSee('0.019800000000000000 ETH')->assertMountedActionModalSee('&lt;script&gt;', escape: false);
        Http::assertNothingSent();
    }

    public function test_merchant_can_read_but_cannot_submit_manual_resolution_or_recheck(): void
    {
        Filament::setCurrentPanel('merchant');
        $this->actingAs($this->requester);
        $order = $this->creditedRequest->order;
        Livewire::test(ManageCryptoPaymentReviews::class)->assertOk()->assertCanSeeTableRecords([$order])
            ->assertActionHidden(TestAction::make('reconcile')->table($order))
            ->assertActionHidden(TestAction::make('recheck')->table($order))
            ->call('mountAction', 'reconcile', [], ['table' => true, 'recordKey' => (string) $order->id])
            ->assertSet('mountedActions', []);
        $this->assertSame(0, CryptoPaymentResolution::query()->count());
        $this->assertFalse(CryptoPaymentReviewResource::canCreate());
    }

    public function test_reconciliation_page_uses_current_ownership_for_direct_records(): void
    {
        $this->merchant->update(['owner_id' => null]);
        Livewire::test(ManageCryptoPaymentReviews::class)->assertCanNotSeeTableRecords([$this->creditedRequest->order]);
        $this->assertFalse(CryptoPaymentReviewResource::canView($this->creditedRequest->order));
    }

    public function test_fee_report_obeys_order_filters_and_reversals_without_counting_original_and_adjustment_twice(): void
    {
        $service = app(CryptoOrderStatisticsService::class);
        $query = Order::query()->where('merchant_id', $this->merchant->id);
        $row = $service->summarize($query)[0];
        $this->assertSame('0.000408000000000000 ETH', $row['fee']);
        $this->assertSame('0.040392000000000000 ETH', $row['net']);
        $this->assertSame([], $service->summarize((clone $query)->where('order_no', 'missing')));
        $this->chainEvent->update(['orphaned_at' => now(), 'confirmed_at' => null]);
        $this->reconcileCrypto();
        $row = $service->summarize($query)[0];
        $this->assertSame('0.000000000000000000 ETH', $row['fee']);
        $this->assertSame('0.000000000000000000 ETH', $row['net']);
        $this->assertSame(1, $row['count']);
        $this->assertSame(__('crypto.order.reversed'), CryptoOrderDisplay::state($this->creditedRequest->order->fresh()));
        app(OrderStatsAggregator::class)->aggregateDate(now());
        $this->assertSame(1, (int) OrderDailyStat::query()->sum('paid_orders'));
        $this->assertSame('0.00', $this->merchant->fresh()->balance);
    }

    public function test_fee_report_does_not_include_other_merchants_or_fiat_income(): void
    {
        $this->makeOrder('fiat', 'fiat', ['settlement_amount' => '99', 'status' => 'paid']);
        $other = Merchant::create(['name' => 'Other', 'status' => true]);
        $this->assertSame([], app(CryptoOrderStatisticsService::class)->summarize(Order::query()->where('merchant_id', $other->id)));
        $rows = app(CryptoOrderStatisticsService::class)->summarize(Order::query());
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_recheck_action_does_not_modify_balance(): void
    {
        $order = $this->creditedRequest->order;
        Livewire::test(ManageCryptoPaymentReviews::class)->callAction(TestAction::make('recheck')->table($order), [
            'reason' => 'Provider recovered', 'mfa_code' => $this->cryptoCode($this->reviewer),
        ])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('manual_recheck', $this->creditedRequest->fresh()->review_reason);
        $this->assertSame('recheck', CryptoPaymentResolution::query()->sole()->action);
        $this->assertSame($this->income->amount_units, $this->assetBalance->fresh()->balance_units);
    }

    public function test_same_asset_on_different_networks_has_separate_exact_fee_totals(): void
    {
        foreach (['ethereum-sepolia', 'tron-shasta'] as $network) {
            $order = $this->makeOrder($network, $network, ['payment_type' => 'crypto', 'status' => 'paid', 'paid_at' => now()]);
            $address = CryptoAddress::factory()->create(['merchant_id' => $this->merchant->id, 'order_id' => $order->id,
                'payment_method_id' => $this->creditedRequest->payment_method_id, 'network' => $network,
                'address' => $network === 'tron-shasta' ? 'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb' : '0x'.str_repeat('f', 40)]);
            $request = CryptoPaymentRequest::factory()->create(['merchant_id' => $this->merchant->id,
                'order_id' => $order->id, 'payment_method_id' => $this->creditedRequest->payment_method_id,
                'crypto_address_id' => $address->id, 'network' => $network, 'asset' => 'USDT', 'decimals' => 6, 'status' => 'credited']);
            $balance = CryptoAssetBalance::factory()->create(['merchant_id' => $this->merchant->id,
                'network' => $network, 'asset' => 'USDT', 'balance_units' => '999999']);
            CryptoLedgerEntry::factory()->create(['merchant_id' => $this->merchant->id, 'order_id' => $order->id,
                'crypto_payment_request_id' => $request->id, 'crypto_asset_balance_id' => $balance->id,
                'network' => $network, 'asset' => 'USDT', 'gross_units' => '1000000', 'fee_units' => '1', 'amount_units' => '999999']);
        }
        $rows = app(CryptoOrderStatisticsService::class)->summarize(Order::query());
        $usdt = array_values(array_filter($rows, fn (array $row): bool => $row['asset'] === 'USDT'));
        $this->assertCount(2, $usdt);
        $this->assertSame(['ethereum-sepolia', 'tron-shasta'], array_column($usdt, 'network'));
        $this->assertSame(['0.000001 USDT', '0.000001 USDT'], array_column($usdt, 'fee'));
        $this->assertSame(['0.999999 USDT', '0.999999 USDT'], array_column($usdt, 'net'));
    }
}

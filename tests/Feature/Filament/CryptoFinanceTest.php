<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CryptoAssetBalanceResource;
use App\Filament\Resources\CryptoAssetBalanceResource\Pages\ManageCryptoAssetBalances;
use App\Filament\Resources\CryptoChainEventResource;
use App\Filament\Resources\CryptoChainEventResource\Pages\ManageCryptoChainEvents;
use App\Filament\Resources\CryptoLedgerEntryResource;
use App\Filament\Resources\CryptoLedgerEntryResource\Pages\ManageCryptoLedgerEntries;
use App\Filament\Resources\CryptoWithdrawalResource;
use App\Filament\Resources\CryptoWithdrawalResource\Pages\ManageCryptoWithdrawals;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoWithdrawal;
use App\Models\Merchant;
use App\Models\Observer;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesCryptoFinance;
use Tests\TestCase;

class CryptoFinanceTest extends TestCase
{
    use CreatesCryptoFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCryptoFinance();
        Filament::setCurrentPanel('merchant');
        app()->setLocale('zh_CN');
        $this->actingAs($this->requester);
    }

    public function test_all_finance_pages_render_exact_amounts_and_read_only_receipt_details(): void
    {
        Livewire::test(ManageCryptoAssetBalances::class)->assertOk()->assertCanSeeTableRecords([$this->assetBalance])
            ->assertSee('0.040392000000000000 ETH')
            ->assertActionVisible(TestAction::make('requestWithdrawal')->table($this->assetBalance));
        Livewire::test(ManageCryptoLedgerEntries::class)->assertOk()->assertCanSeeTableRecords([$this->income])
            ->mountAction(TestAction::make('view')->table($this->income))
            ->assertMountedActionModalSee('0.000408000000000000 ETH')->assertMountedActionModalSee('0.040392000000000000 ETH')
            ->assertDontSee('test-mainnet-credential');
        Livewire::test(ManageCryptoChainEvents::class)->assertOk()->assertCanSeeTableRecords([$this->chainEvent])
            ->mountAction(TestAction::make('view')->table($this->chainEvent))
            ->assertMountedActionModalSee($this->chainEvent->transaction_hash)
            ->assertMountedActionModalSee($this->creditedRequest->cryptoAddress->address);
        Livewire::test(ManageCryptoWithdrawals::class)->assertOk();
        foreach ([CryptoAssetBalanceResource::class, CryptoLedgerEntryResource::class, CryptoChainEventResource::class, CryptoWithdrawalResource::class] as $resource) {
            $this->assertFalse($resource::canCreate());
            $this->assertFalse($resource::canEdit($this->assetBalance));
            $this->assertFalse($resource::canDelete($this->assetBalance));
            $this->assertFalse($resource::canDeleteAny());
        }
        Http::assertNothingSent();
    }

    public function test_merchant_requests_then_platform_reviews_and_manually_writes_off(): void
    {
        Livewire::test(ManageCryptoAssetBalances::class)
            ->callAction(TestAction::make('requestWithdrawal')->table($this->assetBalance), [
                'request_key' => (string) Str::uuid(), 'amount' => '0.01', 'fee_limit' => '0.002',
                'destination_address' => '0x'.str_repeat('b', 40), 'remark' => 'Manual request',
                'mfa_code' => $this->cryptoCode($this->requester),
            ])->assertHasNoActionErrors()->assertNotified();
        $withdrawal = CryptoWithdrawal::query()->sole();
        Livewire::test(ManageCryptoWithdrawals::class)
            ->assertCanSeeTableRecords([$withdrawal])
            ->assertActionHidden(TestAction::make('approve')->table($withdrawal))
            ->assertActionHidden(TestAction::make('reject')->table($withdrawal))
            ->assertActionVisible(TestAction::make('cancel')->table($withdrawal));

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->reviewer);
        Livewire::test(ManageCryptoWithdrawals::class)
            ->callAction(TestAction::make('approve')->table($withdrawal), [
                'reason' => 'Reviewed', 'mfa_code' => $this->cryptoCode($this->reviewer),
            ])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('approved', $withdrawal->refresh()->status);
        $this->assertSame('12000000000000000', $this->assetBalance->refresh()->reserved_units);
        Livewire::test(ManageCryptoWithdrawals::class)
            ->callAction(TestAction::make('complete')->table($withdrawal), [
                'actual_fee' => '0.001', 'manual_reference' => 'OFFLINE-001', 'reason' => 'Evidence checked',
                'manual_confirmation' => true, 'mfa_code' => $this->cryptoCode($this->reviewer),
            ])->assertHasNoActionErrors()->assertNotified();
        $this->assertSame('completed', $withdrawal->refresh()->status);
        Livewire::test(ManageCryptoWithdrawals::class)
            ->mountAction(TestAction::make('view')->table($withdrawal))
            ->assertMountedActionModalSee('OFFLINE-001')->assertMountedActionModalSee('0.029392000000000000 ETH')
            ->assertMountedActionModalSee($this->reviewer->name)->assertMountedActionModalSee(__('crypto.finance.states.completed'));
        $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
        Http::assertNothingSent();
    }

    public function test_merchant_and_manager_cannot_read_or_act_on_other_merchants_records(): void
    {
        $other = Merchant::create(['name' => 'Unrelated merchant']);
        $foreign = CryptoAssetBalance::factory()->create(['merchant_id' => $other->id, 'balance_units' => '1000000000000000000']);
        $foreignWithdrawal = CryptoWithdrawal::factory()->create(['crypto_asset_balance_id' => $foreign->id]);
        foreach ([$this->requester, $this->reviewer] as $user) {
            $this->actingAs($user);
            Livewire::test(ManageCryptoAssetBalances::class)->assertCanSeeTableRecords([$this->assetBalance])->assertCanNotSeeTableRecords([$foreign]);
            Livewire::test(ManageCryptoWithdrawals::class)->assertCanNotSeeTableRecords([$foreignWithdrawal]);
            $this->assertFalse(CryptoAssetBalanceResource::canView($foreign));
            $this->assertFalse(CryptoWithdrawalResource::canView($foreignWithdrawal));
        }
        $admin = $this->cryptoUser(null, superAdmin: true);
        $this->actingAs($admin);
        Livewire::test(ManageCryptoAssetBalances::class)->assertCanSeeTableRecords([$this->assetBalance, $foreign]);
    }

    public function test_form_rejects_overprecision_and_leaves_balances_untouched(): void
    {
        Livewire::test(ManageCryptoAssetBalances::class)
            ->callAction(TestAction::make('requestWithdrawal')->table($this->assetBalance), [
                'request_key' => (string) Str::uuid(), 'amount' => '0.0000000000000000001', 'fee_limit' => '0',
                'destination_address' => '0x'.str_repeat('b', 40), 'mfa_code' => $this->cryptoCode($this->requester),
            ])->assertNotified();
        $this->assertDatabaseCount('crypto_withdrawals', 0);
        $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
    }

    public function test_missing_manual_confirmation_cannot_complete_the_request(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->processCrypto($withdrawal, 'approve');
        $this->actingAs($this->reviewer);
        Filament::setCurrentPanel('admin');
        Livewire::test(ManageCryptoWithdrawals::class)
            ->callAction(TestAction::make('complete')->table($withdrawal), [
                'actual_fee' => '0', 'manual_reference' => 'OFFLINE', 'reason' => 'Checked',
                'manual_confirmation' => false, 'mfa_code' => $this->cryptoCode($this->reviewer),
            ])->assertHasActionErrors(['manual_confirmation']);
        $this->assertSame('approved', $withdrawal->refresh()->status);
    }

    public function test_revoking_access_or_disabling_user_is_enforced_despite_a_stale_session(): void
    {
        $this->assertTrue(CryptoAssetBalanceResource::canViewAny());
        $this->requester->load('permissions');
        User::findOrFail($this->requester->id)->revokePermissionTo(Permissions::CRYPTO_FINANCE_VIEW);
        Livewire::test(ManageCryptoAssetBalances::class)->assertForbidden();
        $this->assertSame(0, CryptoAssetBalanceResource::getEloquentQuery()->count());

        User::findOrFail($this->requester->id)->givePermissionTo(Permissions::CRYPTO_FINANCE_VIEW);
        User::findOrFail($this->requester->id)->update(['status' => false]);
        Livewire::test(ManageCryptoWithdrawals::class)->assertForbidden();
    }

    public function test_fiat_finance_permission_does_not_implicitly_grant_crypto_access(): void
    {
        $this->requester->syncPermissions([Permissions::FINANCE_VIEW, Permissions::WITHDRAWALS_REQUEST, Permissions::WITHDRAWALS_REVIEW]);
        foreach ([ManageCryptoAssetBalances::class, ManageCryptoLedgerEntries::class, ManageCryptoChainEvents::class, ManageCryptoWithdrawals::class] as $page) {
            Livewire::test($page)->assertForbidden();
        }
    }

    public function test_read_only_user_cannot_request_or_review(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->requester->syncPermissions([Permissions::CRYPTO_FINANCE_VIEW]);
        Livewire::test(ManageCryptoAssetBalances::class)
            ->assertActionHidden(TestAction::make('requestWithdrawal')->table($this->assetBalance));
        Livewire::test(ManageCryptoWithdrawals::class)
            ->assertActionHidden(TestAction::make('cancel')->table($withdrawal))
            ->assertActionHidden(TestAction::make('approve')->table($withdrawal));
    }

    public function test_ownership_reassignment_immediately_hides_records_from_the_previous_manager(): void
    {
        $this->actingAs($this->reviewer);
        $this->reviewer->load('ownedMerchants');
        $this->merchant->update(['owner_id' => $this->cryptoUser(null)->id]);
        Livewire::test(ManageCryptoAssetBalances::class)->assertCanNotSeeTableRecords([$this->assetBalance]);
        $this->assertFalse(CryptoChainEventResource::canView($this->chainEvent));
        $this->assertFalse(CryptoLedgerEntryResource::canView($this->income));
    }

    public function test_observers_and_anonymous_sessions_cannot_query_crypto_finance_resources(): void
    {
        auth()->logout();
        $this->assertFalse(CryptoAssetBalanceResource::canViewAny());
        $this->assertSame(0, CryptoAssetBalanceResource::getEloquentQuery()->count());
        auth()->setUser(new Observer(['name' => 'Read-only observer']));
        $this->assertFalse(CryptoChainEventResource::canViewAny());
        $this->assertSame(0, CryptoChainEventResource::getEloquentQuery()->count());
    }

    public function test_filters_keep_networks_and_chain_states_distinct(): void
    {
        $otherNetwork = CryptoAssetBalance::factory()->create([
            'merchant_id' => $this->merchant->id, 'network' => 'ethereum-mainnet', 'balance_units' => '123456789123456789',
        ]);
        Livewire::test(ManageCryptoAssetBalances::class)->filterTable('network', 'ethereum-mainnet')
            ->assertCanSeeTableRecords([$otherNetwork])->assertCanNotSeeTableRecords([$this->assetBalance]);
        Livewire::test(ManageCryptoChainEvents::class)->filterTable('chain_status', 'orphaned')->assertCanNotSeeTableRecords([$this->chainEvent]);
        $this->chainEvent->update(['orphaned_at' => now()]);
        Livewire::test(ManageCryptoChainEvents::class)->filterTable('chain_status', 'orphaned')->assertCanSeeTableRecords([$this->chainEvent])
            ->assertSee(__('crypto.finance.chain_states.orphaned'));
    }

    public function test_notes_are_escaped_in_withdrawal_details(): void
    {
        $withdrawal = $this->requestCrypto(['remark' => '<script>alert("withdrawal")</script>']);
        $view = Livewire::test(ManageCryptoWithdrawals::class)->mountAction(TestAction::make('view')->table($withdrawal));
        $view->assertMountedActionModalSee('&lt;script&gt;', escape: false);
        $view->assertMountedActionModalDontSee('<script>alert("withdrawal")</script>', escape: false);
    }

    public function test_other_merchants_cannot_read_chain_events_or_credit_receipts(): void
    {
        $withdrawal = $this->requestCrypto();
        $other = Merchant::create(['name' => 'Other finance tenant']);
        $this->actingAs($this->cryptoUser($other->id));
        Livewire::test(ManageCryptoChainEvents::class)->assertCanNotSeeTableRecords([$this->chainEvent]);
        Livewire::test(ManageCryptoLedgerEntries::class)->assertCanNotSeeTableRecords([$this->income]);
        Livewire::test(ManageCryptoWithdrawals::class)->assertCanNotSeeTableRecords([$withdrawal]);
        $this->assertFalse(CryptoChainEventResource::canView($this->chainEvent));
        $this->assertFalse(CryptoLedgerEntryResource::canView($this->income));
    }

    public function test_request_permission_is_rechecked_after_the_action_modal_was_opened(): void
    {
        $page = Livewire::test(ManageCryptoAssetBalances::class)
            ->mountAction(TestAction::make('requestWithdrawal')->table($this->assetBalance))
            ->setActionData([
                'request_key' => (string) Str::uuid(), 'amount' => '0.01', 'fee_limit' => '0',
                'destination_address' => '0x'.str_repeat('b', 40), 'mfa_code' => $this->cryptoCode($this->requester),
            ]);
        User::findOrFail($this->requester->id)->revokePermissionTo(Permissions::CRYPTO_WITHDRAWALS_REQUEST);
        $page->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('crypto_withdrawals', 0);
        $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
    }
}

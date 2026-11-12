<?php

namespace Tests\Feature;

use App\Exceptions\BalanceOperationException;
use App\Models\CryptoAssetBalance;
use App\Models\CryptoWithdrawalEvent;
use App\Models\Merchant;
use App\Models\User;
use App\Services\CryptoBalanceService;
use App\Support\CryptoAmount;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoFinance;
use Tests\TestCase;

class CryptoWithdrawalTest extends TestCase
{
    use CreatesCryptoFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCryptoFinance();
    }

    public function test_request_approval_and_manual_completion_only_mutate_crypto_accounting_once(): void
    {
        $fiat = $this->merchant->refresh()->only(['balance', 'frozen_balance', 'pending_withdrawal_balance', 'pending_settlement_balance']);
        $withdrawal = $this->requestCrypto();
        $this->assertSame('12000000000000000', $this->assetBalance->refresh()->reserved_units);
        $this->assertSame('28392000000000000', $this->assetBalance->availableUnits());

        $this->processCrypto($withdrawal, 'approve');
        $this->assertSame('approved', $withdrawal->refresh()->status);
        $this->assertSame('40392000000000000', $this->assetBalance->refresh()->balance_units);
        $this->assertSame('12000000000000000', $this->assetBalance->reserved_units);

        $this->processCrypto($withdrawal, 'complete');
        $this->assertSame('completed', $withdrawal->refresh()->status);
        $this->assertSame('1000000000000000', $withdrawal->actual_fee_units);
        $this->assertSame('OFFLINE-2026-001', $withdrawal->manual_reference);
        $this->assertSame('29392000000000000', $this->assetBalance->refresh()->balance_units);
        $this->assertSame('0', $this->assetBalance->reserved_units);
        $this->assertSame($fiat, $this->merchant->refresh()->only(array_keys($fiat)));
        $this->assertDatabaseCount('merchant_withdrawals', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        $this->assertSame(['request', 'approve', 'complete'], $withdrawal->events()->pluck('action')->all());
        $event = $withdrawal->events()->where('action', 'complete')->sole();
        $this->assertSame('40392000000000000', $event->balance_before_units);
        $this->assertSame('29392000000000000', $event->balance_after_units);
        $this->assertSame('12000000000000000', $event->reserved_before_units);
        $this->assertSame('0', $event->reserved_after_units);
        Http::assertNothingSent();

        try {
            $this->processCrypto($withdrawal, 'complete');
            $this->fail('A repeated completion was accepted.');
        } catch (BalanceOperationException) {
            $this->assertSame('29392000000000000', $this->assetBalance->refresh()->balance_units);
            $this->assertDatabaseCount('crypto_withdrawal_events', 3);
        }
    }

    public function test_request_key_retries_reserve_once_and_cannot_change_the_authorized_request(): void
    {
        $key = (string) Str::uuid();
        $first = $this->requestCrypto(['key' => $key]);
        $again = $this->requestCrypto(['key' => $key]);
        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('crypto_withdrawal_events', 1);
        try {
            $this->requestCrypto(['key' => $key, 'amount' => '0.02']);
            $this->fail('Changed idempotent request was accepted.');
        } catch (BalanceOperationException) {
            $this->assertSame('12000000000000000', $this->assetBalance->refresh()->reserved_units);
        }
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_or_unfunded_requests_cannot_reserve_funds(array $changes): void
    {
        try {
            $this->requestCrypto($changes);
            $this->fail('Invalid request was accepted.');
        } catch (BalanceOperationException) {
            $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
            $this->assertDatabaseCount('crypto_withdrawals', 0);
            $this->assertDatabaseCount('crypto_withdrawal_events', 0);
        }
    }

    public static function invalidRequests(): array
    {
        return [
            'zero' => [['amount' => '0']], 'negative' => [['amount' => '-0.01']],
            'exponent' => [['amount' => '1e-2']], 'excess precision' => [['amount' => '0.0000000000000000001']],
            'negative fee' => [['fee_limit' => '-0.1']], 'principal overdraft' => [['amount' => '1']],
            'fee overdraft' => [['amount' => '0.04']], 'sum overflow' => [['amount' => CryptoAmount::format(CryptoAmount::MAX_UNITS, 18)]],
            'wrong network address' => [['address' => 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8']],
            'zero address' => [['address' => '0x'.str_repeat('0', 40)]],
            'no key' => [['key' => 'bad']], 'too long note' => [['remark' => str_repeat('x', 501)]],
        ];
    }

    public function test_other_reservations_are_included_in_available_balance_and_preserved_on_release(): void
    {
        $first = $this->requestCrypto();
        $second = $this->requestCrypto(['amount' => '0.02']);
        try {
            $this->requestCrypto();
            $this->fail('Reservations were ignored.');
        } catch (BalanceOperationException) {
            $this->assertSame('34000000000000000', $this->assetBalance->refresh()->reserved_units);
        }
        $this->processCrypto($first, 'cancel');
        $this->assertSame('22000000000000000', $this->assetBalance->refresh()->reserved_units);
        $this->processCrypto($second, 'reject');
        $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
        $this->assertSame('40392000000000000', $this->assetBalance->balance_units);
        $this->assertDatabaseCount('crypto_withdrawal_events', 4);
    }

    #[DataProvider('badCompletions')]
    public function test_completion_requires_approval_evidence_and_a_fee_within_the_authorized_cap(bool $approve, array $changes): void
    {
        $withdrawal = $this->requestCrypto();
        if ($approve) {
            $this->processCrypto($withdrawal, 'approve');
        }
        try {
            $this->processCrypto($withdrawal, 'complete', changes: $changes);
            $this->fail('Unsafe write-off was accepted.');
        } catch (BalanceOperationException) {
            $this->assertSame('40392000000000000', $this->assetBalance->refresh()->balance_units);
            $this->assertSame('12000000000000000', $this->assetBalance->reserved_units);
            $this->assertNull($withdrawal->refresh()->completed_at);
        }
    }

    public static function badCompletions(): array
    {
        return [
            'not approved' => [false, []], 'fee above cap' => [true, ['fee' => '0.002000000000000001']],
            'missing evidence' => [true, ['reference' => '']], 'missing reason' => [true, ['reason' => '']],
            'fee precision' => [true, ['fee' => '0.0000000000000000001']],
        ];
    }

    public function test_approved_request_requires_no_payment_attestation_to_release_and_cannot_be_cancelled(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->processCrypto($withdrawal, 'approve');
        foreach (['cancel', 'reject'] as $action) {
            try {
                $this->processCrypto($withdrawal, $action);
                $this->fail('Approved reservation was released without safeguards.');
            } catch (BalanceOperationException) {
                $this->assertSame('12000000000000000', $this->assetBalance->refresh()->reserved_units);
            }
        }
        $this->processCrypto($withdrawal, 'reject', changes: ['no_external_payment' => true]);
        $this->assertSame('rejected', $withdrawal->refresh()->status);
        $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
        $this->assertSame(['request', 'approve', 'reject'], $withdrawal->events()->pluck('action')->all());
    }

    public function test_merchant_accounts_cannot_review_or_write_off_even_with_the_permission(): void
    {
        $withdrawal = $this->requestCrypto();
        foreach (['approve', 'reject', 'complete'] as $action) {
            try {
                $this->processCrypto($withdrawal, $action, $this->requester);
                $this->fail('Merchant bypassed platform-only review.');
            } catch (AuthorizationException) {
                $this->assertSame('pending', $withdrawal->refresh()->status);
            }
        }
    }

    public function test_another_user_in_the_same_merchant_cannot_cancel_the_request(): void
    {
        $withdrawal = $this->requestCrypto();
        $other = $this->cryptoUser($this->merchant->id);
        $this->expectException(AuthorizationException::class);
        $this->processCrypto($withdrawal, 'cancel', $other);
    }

    public function test_platform_manager_access_is_rechecked_when_merchant_ownership_changes(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->reviewer->load('ownedMerchants');
        $this->merchant->update(['owner_id' => $this->cryptoUser(null)->id]);
        try {
            $this->processCrypto($withdrawal, 'approve');
            $this->fail('Stale ownership authorized a review.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $withdrawal->refresh()->status);
        }
    }

    public function test_forged_merchant_or_balance_ids_do_not_reach_other_accounts(): void
    {
        $other = Merchant::create(['name' => 'Other merchant']);
        $balance = CryptoAssetBalance::factory()->create(['merchant_id' => $other->id, 'balance_units' => '1000000000000000000']);
        try {
            $this->requestCrypto(['balance_id' => $balance->id]);
            $this->fail('Foreign account accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame('0', $balance->refresh()->reserved_units);
        }
        try {
            $this->requestCrypto(['merchant_id' => $other->id, 'balance_id' => $balance->id]);
            $this->fail('Foreign merchant accepted.');
        } catch (AuthorizationException) {
            $this->assertSame('0', $balance->refresh()->reserved_units);
            $this->assertDatabaseCount('crypto_withdrawals', 0);
        }
    }

    #[DataProvider('revokedAccess')]
    public function test_current_database_authorization_overrides_stale_actor_instances(string $change): void
    {
        $this->requester->load('permissions');
        match ($change) {
            'permission' => User::findOrFail($this->requester->id)->revokePermissionTo(Permissions::CRYPTO_WITHDRAWALS_REQUEST),
            'disabled' => User::findOrFail($this->requester->id)->update(['status' => false]),
            'merchant' => User::findOrFail($this->requester->id)->update(['merchant_id' => Merchant::create(['name' => 'Moved merchant'])->id]),
        };
        $this->expectException(AuthorizationException::class);
        $this->requestCrypto();
    }

    public static function revokedAccess(): array
    {
        return [['permission'], ['disabled'], ['merchant']];
    }

    public function test_wrong_or_missing_totp_blocks_every_money_action(): void
    {
        $this->expectException(BalanceOperationException::class);
        try {
            app(CryptoBalanceService::class)->requestWithdrawal(
                $this->merchant->id, $this->assetBalance->id, $this->requester, (string) Str::uuid(),
                '0.01', '0', '0x'.str_repeat('b', 40), null, 'invalid',
            );
        } finally {
            $this->assertDatabaseCount('crypto_withdrawals', 0);
        }
    }

    #[DataProvider('reviewActions')]
    public function test_wrong_totp_prevents_state_changes(string $action): void
    {
        $withdrawal = $this->requestCrypto();
        if ($action === 'complete') {
            $this->processCrypto($withdrawal, 'approve');
        }
        $user = $action === 'cancel' ? $this->requester : $this->reviewer;
        $before = $withdrawal->refresh()->status;
        try {
            app(CryptoBalanceService::class)->processWithdrawal(
                $withdrawal->merchant_id, $withdrawal->id, $user, $action, 'Test', 'invalid', '0', 'Ref',
            );
            $this->fail('Invalid TOTP accepted.');
        } catch (BalanceOperationException) {
            $this->assertSame($before, $withdrawal->refresh()->status);
        }
    }

    public static function reviewActions(): array
    {
        return [['approve'], ['reject'], ['cancel'], ['complete']];
    }

    public function test_unbound_authenticator_cannot_request_even_for_a_super_admin(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->expectException(BalanceOperationException::class);
        app(CryptoBalanceService::class)->requestWithdrawal(
            $this->merchant->id, $this->assetBalance->id, $admin, (string) Str::uuid(),
            '0.01', '0', '0x'.str_repeat('b', 40), null, '123456',
        );
    }

    public function test_income_under_review_blocks_new_spending_but_allows_releasing_pending_reservations(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->creditedRequest->update(['status' => 'review', 'review_reason' => 'credited_reorg']);
        foreach (['request', 'approve'] as $action) {
            try {
                $action === 'request' ? $this->requestCrypto() : $this->processCrypto($withdrawal, $action);
                $this->fail('Disputed income was spendable.');
            } catch (BalanceOperationException) {
                $this->assertSame('12000000000000000', $this->assetBalance->refresh()->reserved_units);
            }
        }
        $this->processCrypto($withdrawal, 'reject');
        $this->assertSame('0', $this->assetBalance->refresh()->reserved_units);
    }

    public function test_orphaned_credit_blocks_completion_even_if_request_status_was_not_updated(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->processCrypto($withdrawal, 'approve');
        $this->chainEvent->update(['orphaned_at' => now()]);
        $this->expectException(BalanceOperationException::class);
        try {
            $this->processCrypto($withdrawal, 'complete');
        } finally {
            $this->assertSame('40392000000000000', $this->assetBalance->refresh()->balance_units);
            $this->assertSame('approved', $withdrawal->refresh()->status);
        }
    }

    public function test_corrupt_reservation_cannot_be_released_or_charged(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->assetBalance->update(['reserved_units' => '1']);
        $this->expectException(BalanceOperationException::class);
        $this->processCrypto($withdrawal, 'reject');
    }

    public function test_same_symbol_on_another_network_is_untouched_and_one_wei_is_exact(): void
    {
        $other = CryptoAssetBalance::factory()->create([
            'merchant_id' => $this->merchant->id, 'network' => 'ethereum-mainnet', 'balance_units' => '900719925474099312345678',
        ]);
        $withdrawal = $this->requestCrypto(['amount' => '0.000000000000000001', 'fee_limit' => '0']);
        $this->processCrypto($withdrawal, 'approve');
        $this->processCrypto($withdrawal, 'complete', changes: ['fee' => '0']);
        $this->assertSame('40391999999999999', $this->assetBalance->refresh()->balance_units);
        $this->assertSame('900719925474099312345678', $other->refresh()->balance_units);
        $this->assertSame('0', $other->reserved_units);
    }

    public function test_audit_and_request_identity_cannot_be_edited_or_deleted(): void
    {
        $withdrawal = $this->requestCrypto();
        $event = $withdrawal->events()->sole();
        foreach ([fn () => $event->update(['reason' => 'overwrite']), fn () => $event->delete(),
            fn () => $withdrawal->update(['destination_address' => '0x'.str_repeat('c', 40)]),
            fn () => $withdrawal->delete(), fn () => $this->assetBalance->update(['asset' => 'USDT'])] as $operation) {
            try {
                $operation();
                $this->fail('Audit identity was mutable.');
            } catch (LogicException) {
                $this->assertDatabaseCount('crypto_withdrawal_events', 1);
            }
        }
    }

    #[DataProvider('assetQuantities')]
    public function test_each_supported_asset_reserves_and_deducts_exactly_one_smallest_unit(string $network, string $asset, string $quantity, string $address): void
    {
        $balance = CryptoAssetBalance::query()->firstOrCreate([
            'merchant_id' => $this->merchant->id, 'network' => $network, 'asset' => $asset,
        ], ['balance_units' => '10000', 'reserved_units' => '0']);
        $before = $balance->balance_units;
        $withdrawal = $this->requestCrypto([
            'balance_id' => $balance->id, 'amount' => $quantity, 'fee_limit' => '0', 'address' => $address,
        ]);
        $this->assertSame('1', $withdrawal->amount_units);
        $this->assertSame('1', $balance->refresh()->reserved_units);
        $this->processCrypto($withdrawal, 'approve');
        $this->processCrypto($withdrawal, 'complete', changes: ['fee' => '0']);
        $this->assertSame(bcsub($before, '1', 0), $balance->refresh()->balance_units);
        $this->assertSame('0', $balance->reserved_units);
        Http::assertNothingSent();
    }

    public static function assetQuantities(): array
    {
        return [
            ['bitcoin-mainnet', 'BTC', '0.00000001', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'],
            ['bitcoin-testnet', 'BTC', '0.00000001', 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn'],
            ['litecoin-mainnet', 'LTC', '0.00000001', 'L'.str_repeat('a', 33)],
            ['litecoin-testnet', 'LTC', '0.00000001', 'mipcBbFg9gMiCh81Kj8tqqdgoZub1ZJRfn'],
            ['ethereum-mainnet', 'ETH', '0.000000000000000001', '0x'.str_repeat('b', 40)],
            ['ethereum-sepolia', 'ETH', '0.000000000000000001', '0x'.str_repeat('b', 40)],
            ['ethereum-mainnet', 'USDT', '0.000001', '0x'.str_repeat('b', 40)],
            ['ethereum-sepolia', 'USDT', '0.000001', '0x'.str_repeat('b', 40)],
            ['tron-mainnet', 'USDT', '0.000001', 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8'],
            ['tron-shasta', 'USDT', '0.000001', 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8'],
        ];
    }

    public function test_audit_write_failure_rolls_back_the_balance_and_withdrawal_transition(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->processCrypto($withdrawal, 'approve');
        Event::listen('eloquent.creating: '.CryptoWithdrawalEvent::class, function (CryptoWithdrawalEvent $event): void {
            if ($event->action === 'complete') {
                throw new \RuntimeException('Simulated audit storage failure');
            }
        });
        try {
            $this->processCrypto($withdrawal, 'complete');
            $this->fail('Audit storage failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit storage failure', $exception->getMessage());
            $this->assertSame('approved', $withdrawal->refresh()->status);
            $this->assertSame('40392000000000000', $this->assetBalance->refresh()->balance_units);
            $this->assertSame('12000000000000000', $this->assetBalance->reserved_units);
            $this->assertDatabaseCount('crypto_withdrawal_events', 2);
        }
    }

    #[DataProvider('unsettledCredit')]
    public function test_incomplete_credit_evidence_blocks_spending_without_relying_only_on_request_status(array $requestChanges, array $eventChanges): void
    {
        $this->creditedRequest->update($requestChanges);
        $this->chainEvent->update($eventChanges);
        $this->expectException(BalanceOperationException::class);
        $this->requestCrypto();
    }

    public static function unsettledCredit(): array
    {
        return [
            'review reason' => [['review_reason' => 'manual_review_required'], []],
            'unverified event' => [[], ['verified_at' => null]],
            'unconfirmed event' => [[], ['confirmed_at' => null]],
        ];
    }

    public function test_finalized_withdrawals_cannot_be_reopened_by_model_updates(): void
    {
        $withdrawal = $this->requestCrypto();
        $this->processCrypto($withdrawal, 'cancel');
        $this->expectException(LogicException::class);
        $withdrawal->refresh()->update(['status' => 'pending']);
    }
}

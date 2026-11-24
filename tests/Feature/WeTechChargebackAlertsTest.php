<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ChargebackAlert;
use App\Models\ChargebackChannelBinding;
use App\Models\ChargebackProviderAccount;
use App\Models\ChargebackProviderSetting;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ChargebackAlerts\ChargebackAccountConfiguration;
use App\Services\ChargebackAlerts\ChargebackAlertDriver;
use App\Services\ChargebackAlerts\ChargebackAlertDrivers;
use App\Services\ChargebackAlerts\ChargebackAlertMatcher;
use App\Services\ChargebackAlerts\ChargebackFeedback;
use App\Services\ChargebackAlerts\ChargebackProviderEvent;
use App\Services\ChargebackAlerts\WebhookEnvelope;
use App\Services\ChargebackAlerts\WebhookRejected;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class WeTechChargebackAlertsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private ChargebackProviderAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->createMerchantAndApplication();
        $this->account = ChargebackProviderAccount::factory()->create(['external_id' => 'fixture-wetech-account']);
        Http::preventStrayRequests();
    }

    public function test_ethoca_signature_rejects_tampering_and_replayed_notice_is_stored_once_without_card_data(): void
    {
        $payload = $this->ethoca() + ['pay_card_no' => '4111111111111111', 'order_duration' => 0];
        $body = $this->signed($payload);
        $body['trade_amount'] = 110;
        $this->postJson('/api/webhooks/chargeback/wetech', $body)->assertUnauthorized();

        $old = $payload + ['timestamp' => now()->subDays(8)->getTimestampMs()];
        $this->push($old)->assertUnauthorized();
        $this->assertDatabaseCount('chargeback_alerts', 0);

        $this->push($payload)->assertOk()->assertExactJson(['code' => 0, 'msg' => 'OK']);
        $this->travel(5)->minutes();
        $this->push($payload)->assertOk();
        $alert = ChargebackAlert::query()->sole();
        $this->assertSame('100.00', $alert->trade_amount);
        $this->assertSame('100.00', $alert->case_amount);
        $this->assertSame(now()->subMinutes(5)->addHours(24)->toDateTimeString(), $alert->feedback_due_at->toDateTimeString());
        $this->assertDatabaseCount('chargeback_alert_events', 1);
        $this->assertStringNotContainsString('4111111111111111', DB::table('chargeback_alert_events')->value('details'));
        Http::assertNothingSent();
    }

    public function test_provider_module_accepts_a_separate_header_signing_strategy_and_acknowledgement(): void
    {
        $adapter = new class implements ChargebackAlertDriver
        {
            public function code(): string
            {
                return 'fixture_header';
            }

            public function label(): string
            {
                return 'Header provider fixture';
            }

            public function signingFields(): array
            {
                return ['token' => 'Verify token'];
            }

            public function accountIdentifierLabel(): string
            {
                return 'API Account';
            }

            public function services(): array
            {
                return ['alert' => 'Alert'];
            }

            public function referenceTypes(string $service): array
            {
                return [ChargebackChannelBinding::ARN];
            }

            public function identify(WebhookEnvelope $webhook): string
            {
                return $webhook->headers['x-api-account'][0] ?? throw new WebhookRejected(401);
            }

            public function authenticate(WebhookEnvelope $webhook, ChargebackProviderAccount $account): array
            {
                if (! hash_equals($account->signing_config['token'], $webhook->headers['x-verify-token'][0] ?? '')) {
                    throw new WebhookRejected(401);
                }

                return [new ChargebackProviderEvent(ChargebackProviderEvent::NOTICE, 'alert', trim($webhook->body),
                    CarbonImmutable::now(), ['scope_key' => 'FIXTURE', 'occurred_at' => now()->toDateTimeString()])];
            }

            public function acknowledgement(?WebhookRejected $error = null): array
            {
                return ['status' => $error?->httpStatus ?? 204, 'body' => null];
            }
        };
        app()->instance('fixture-header-driver', $adapter);
        app()->tag(['fixture-header-driver'], ChargebackAlertDrivers::TAG);
        $operator = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $account = app(ChargebackAccountConfiguration::class)->save($operator, [
            'provider' => 'fixture_header', 'external_id' => 'test-header-account', 'environment' => 'test',
            'timezone' => 'Asia/Shanghai', 'signing_config' => ['token' => 'custom-header-token'],
            'services' => ['alert'], 'name' => 'Header fixture', 'enabled' => true,
        ]);

        $this->call('POST', '/api/webhooks/chargeback/fixture_header', [], [], [], [
            'HTTP_X_API_ACCOUNT' => 'test-header-account', 'HTTP_X_VERIFY_TOKEN' => 'wrong',
        ], 'header-alert')->assertUnauthorized();
        $this->assertDatabaseCount('chargeback_alerts', 0);
        $this->call('POST', '/api/webhooks/chargeback/fixture_header', [], [], [], [
            'HTTP_X_API_ACCOUNT' => 'test-header-account', 'HTTP_X_VERIFY_TOKEN' => 'custom-header-token',
        ], 'header-alert')->assertNoContent();
        $this->assertDatabaseHas('chargeback_alerts', [
            'chargeback_provider_account_id' => $account->id, 'external_id' => 'header-alert', 'service' => 'alert',
        ]);
    }

    public function test_a_shared_registration_resolves_to_the_real_order_merchant_with_same_merchant_order_number(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $otherMerchant = Merchant::query()->create(['name' => 'Other Merchant', 'status' => true]);
        $a = $this->makePaymentMethod('a-original');
        $b = $this->makePaymentMethod('b-copy');
        $b->update(['merchant_id' => $otherMerchant->id]);
        $old = $this->makeOrder('A-PAID1', 'SameNumber', [
            'payment_method_id' => $a->id, 'transaction_id' => 'txn-a-one', 'status' => 'paid', 'paid_at' => now()->subHour(),
        ]);
        $application = Application::createWithCredentials([
            'merchant_id' => $otherMerchant->id, 'name' => 'Merchant B App', 'website' => 'https://merchant-b.example',
        ]);
        $real = $this->makeOrder('B-PAID1', 'SameNumber', [
            'merchant_id' => $otherMerchant->id, 'application_id' => $application->id,
            'payment_method_id' => $b->id, 'transaction_id' => 'txn-b-one', 'status' => 'paid', 'paid_at' => now()->subHour(),
        ]);
        foreach ([$a, $b] as $method) {
            $this->bind($admin, $method->id, 'ethoca', 'SHARED-MID', ChargebackChannelBinding::PSP_TRANSACTION);
        }

        $this->push($this->ethoca(['transaction_id' => 'txn-b-one', 'merchant_id' => $this->merchant->id]))->assertOk();
        $this->artisan('chargeback-alerts:process')->assertSuccessful();
        $alert = ChargebackAlert::query()->sole();
        $this->assertSame($real->id, $alert->order_id);
        $this->assertSame($otherMerchant->id, $alert->merchant_id);
        $this->assertSame('matched', $alert->match_status);
        $this->assertSame('paid', $real->fresh()->status);
        $this->assertSame('paid', $old->fresh()->status);
        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    public function test_rdr_native_signed_array_updates_charge_level_and_rejects_a_malformed_batch_atomically(): void
    {
        $row = [
            'rw_id' => 'rdr-case', 'mid' => 'MID / 10', 'descriptor' => '特约商户',
            'trade_amount' => '101.00USD', 'amount' => '99.00USD',
            'receive_time' => now()->toDateTimeString(), 'charge_level' => 'Tier 1', 'status' => 'Open',
        ];
        $this->push(['data' => [$row, ['rw_id' => 'bad']]])->assertBadRequest();
        $this->assertDatabaseCount('chargeback_alerts', 0);

        $first = $this->signed(['data' => [$row]]);
        $this->postJson('/api/webhooks/chargeback/wetech', $first)->assertOk();
        $this->travel(1)->minute();
        $this->push(['data' => [array_replace($row, ['charge_level' => 'Tier 2', 'status' => 'Resolved'])]])->assertOk();
        $this->postJson('/api/webhooks/chargeback/wetech', $first)->assertOk();
        $this->push(['data' => json_encode($row, JSON_THROW_ON_ERROR)])->assertBadRequest();
        $alert = ChargebackAlert::query()->sole();

        $this->assertSame('Tier 2', $alert->charge_level);
        $this->assertSame('Resolved', $alert->remote_status);
        $this->assertSame('99.00', $alert->case_amount);
        $this->assertSame('USD', $alert->case_currency);
        $this->assertDatabaseCount('chargeback_alert_events', 2);
        $this->assertDatabaseCount('order_refunds', 0);
    }

    public function test_settings_share_the_webhook_and_out_of_order_status_cannot_roll_a_setting_back(): void
    {
        $older = $this->signed(['id' => '23', 'mid' => 'M-1', 'stauts' => '-1', 'descriptors' => ['Shop']]);
        $this->travel(1)->minute();
        $this->push(['id' => '23', 'mid' => 'M-1', 'status' => 2, 'descriptors' => ['Other Shop']])->assertOk();
        $this->postJson('/api/webhooks/chargeback/wetech', $older)->assertOk();
        $this->push(['data' => ['id' => 23, 'descriptor' => 'Shop', 'status' => 1]])->assertOk();

        $this->assertDatabaseCount('chargeback_alerts', 0);
        $this->assertSame('2', ChargebackProviderSetting::query()->where('service', 'rdr')->sole()->remote_status);
        $this->assertSame('Shop', ChargebackProviderSetting::query()->where('service', 'ethoca')->sole()->scope_key);
    }

    public function test_operator_can_verify_arn_and_later_alerts_reuse_it_even_after_the_binding_ends(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $method = $this->makePaymentMethod('arn-channel');
        $existing = $this->makeOrder('ARN-PAID1', 'ARN-M1', [
            'payment_method_id' => $method->id, 'status' => 'paid', 'paid_at' => now()->subHour(),
        ]);
        $this->bind($admin, $method->id, 'ethoca', 'SHARED-MID', ChargebackChannelBinding::ARN);
        $this->push($this->ethoca(['arn' => '85233344445555000000000']))->assertOk();
        $this->artisan('chargeback-alerts:process')->assertSuccessful();
        $alert = ChargebackAlert::query()->sole();
        $this->assertNull($alert->order_id);
        $matcher = app(ChargebackAlertMatcher::class);
        $this->assertSame([$existing->id], $matcher->candidates($admin, $alert)->pluck('id')->all());
        $matcher->attach($admin, $alert->id, $existing->order_no, 'ARN verified in acquirer records', true);
        app(ChargebackAccountConfiguration::class)->setBinding($admin, $this->account->id, $method->id, false, [
            'service' => 'ethoca', 'scope_key' => 'SHARED-MID',
        ]);
        $this->travel(5)->minutes();

        $this->push($this->ethoca(['rw_id' => 'second-alert', 'arn' => '85233344445555000000000']))->assertOk();
        $this->artisan('chargeback-alerts:process')->assertSuccessful();
        $this->assertSame($existing->id, ChargebackAlert::query()->where('external_id', 'second-alert')->sole()->order_id);
        $this->assertDatabaseCount('order_chargeback_references', 1);
    }

    public function test_ethoca_feedback_matrix_and_provider_confirmation_after_transport_timeout(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->push($this->ethoca())->assertOk();
        $alert = ChargebackAlert::query()->sole();
        $service = app(ChargebackFeedback::class);
        try {
            $service->request($admin, $alert->id, ['outcome' => 'notfound', 'refund_status' => 'not_settled']);
            $this->fail('An invalid outcome/status pair was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund_status', $exception->errors());
        }
        $feedback = $service->request($admin, $alert->id, ['outcome' => 'notfound', 'refund_status' => 'not_refunded']);

        Http::fake(['https://xp.test.wetech.vip/api/open/rw/eth_oca/feedback' => Http::failedConnection()]);
        $this->artisan('chargeback-alerts:process')->assertSuccessful();
        $this->assertSame('unknown', $feedback->fresh()->status);
        $this->travel(10)->minutes();
        $this->artisan('chargeback-alerts:process')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['rw_id'] === $alert->external_id && $request['outcome'] === 'notfound'
            && $request['sign'] === $this->signed($request->data())['sign']);

        $this->push(['rw_id' => $alert->external_id, 'feedback_time' => now()->toDateTimeString(),
            'outcome' => 'notfound', 'refund_status' => 'not_refunded'])->assertOk();
        $this->assertSame('submitted', $feedback->fresh()->status);
        $this->assertSame('confirmed_by_provider', $feedback->fresh()->result_code);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
    }

    public function test_reconciliation_paginates_only_selected_services_and_preserves_resolved_descriptor_from_callback(): void
    {
        $this->account->update(['services' => ['ethoca']]);
        $row = $this->ethoca(['descriptor' => 'SHOP-ONE*HK', 'source_descriptor' => 'SHOP-ONE']);
        $this->push($row)->assertOk();
        unset($row['source_descriptor']);
        $this->travel(1)->minute();

        Http::fake([
            'https://xp.test.wetech.vip/api/open/rw/eth_oca/list*' => function (Request $request) use ($row) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $parameters);
                $this->assertSame($this->signed($parameters)['sign'], $parameters['sign']);
                $this->assertArrayHasKey('alert_start_time', $parameters);
                $this->assertArrayNotHasKey('push_status', $parameters);
                $records = (int) $parameters['page'] === 1 ? [$row] : [array_replace($row, ['rw_id' => 'from-pull'])];

                return Http::response(['code' => 0, 'data' => ['records' => $records, 'pages' => 2]]);
            },
            'https://xp.test.wetech.vip/api/open/rw/eth_oca/descriptor/list*' => Http::response(['code' => 0, 'data' => [
                'records' => [['id' => 4, 'descriptor' => 'SHOP-ONE', 'status' => 2]], 'pages' => 1,
            ]]),
        ]);
        $this->artisan('chargeback-alerts:sync')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertSame('SHOP-ONE', ChargebackAlert::query()->where('external_id', 'ethoca-case')->sole()->scope_key);
        $this->assertFalse(ChargebackAlert::query()->where('external_id', 'ethoca-case')->sole()->evidence_conflict);
        $this->assertDatabaseCount('chargeback_alerts', 2);
        $this->assertDatabaseHas('chargeback_provider_settings', ['scope_key' => 'SHOP-ONE', 'remote_status' => '2']);
        $this->assertNotNull($this->account->fresh()->last_sync_at);
    }

    public function test_one_failed_provider_stream_does_not_stop_other_lists_from_syncing(): void
    {
        Http::fake([
            'https://xp.test.wetech.vip/api/open/rw/rdr/list*' => Http::response(['code' => 5001], 500),
            'https://xp.test.wetech.vip/api/open/rw/eth_oca/list*' => Http::response(['code' => 0, 'data' => [
                'records' => [$this->ethoca()], 'pages' => 1,
            ]]),
            'https://xp.test.wetech.vip/api/open/rw/eth_oca/descriptor/list*' => Http::response(['code' => 0, 'data' => [
                'records' => [['id' => 4, 'descriptor' => 'SHOP-ONE', 'status' => 2]], 'pages' => 1,
            ]]),
            'https://xp.test.wetech.vip/api/open/rw/rdr/setting/list*' => Http::response(['code' => 0, 'data' => [
                'records' => [['id' => 7, 'mid' => 'MID-ONE', 'status' => 2]], 'pages' => 1,
            ]]),
        ]);

        $this->artisan('chargeback-alerts:sync')->assertFailed();
        $this->assertDatabaseCount('chargeback_alerts', 1);
        $this->assertDatabaseCount('chargeback_provider_settings', 2);
        $this->assertSame('5001', $this->account->fresh()->sync_error);
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $this->account->fresh()->next_sync_at->toDateTimeString());
    }

    private function bind(User $admin, int $methodId, string $service, string $key, string $reference): void
    {
        app(ChargebackAccountConfiguration::class)->setBinding($admin, $this->account->id, $methodId, true, [
            'service' => $service, 'scope_key' => $key, 'reference_type' => $reference,
            'valid_from' => now()->subDays(2)->toDateTimeString(),
        ]);
    }

    private function ethoca(array $overrides = []): array
    {
        return array_replace([
            'rw_id' => 'ethoca-case', 'alert_timestamp' => now()->toDateTimeString(),
            'trade_time' => now()->subHour()->toDateTimeString(), 'descriptor' => 'SHARED-MID',
            'trade_amount' => 100, 'trade_currency' => 'USD', 'amount' => 100, 'currency' => 'USD', 'type' => 'Fraud',
        ], $overrides);
    }

    private function signed(array $parameters): array
    {
        unset($parameters['sign']);
        $parameters += ['secret_id' => $this->account->external_id, 'timestamp' => (string) now()->getTimestampMs()];
        $sorted = $parameters;
        ksort($sorted, SORT_STRING);
        $canonical = [];
        foreach ($sorted as $key => $value) {
            if ($value !== null && $value !== '') {
                $canonical[] = $key.'='.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $value);
            }
        }
        $parameters['sign'] = base64_encode(hash_hmac('sha1', implode('&', $canonical), $this->account->signing_config['secret_key'], true));

        return $parameters;
    }

    private function push(array $parameters): TestResponse
    {
        return $this->postJson('/api/webhooks/chargeback/wetech', $this->signed($parameters));
    }
}

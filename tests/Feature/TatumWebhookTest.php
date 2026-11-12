<?php

namespace Tests\Feature;

use App\Models\CryptoAddressMonitor;
use App\Models\CryptoWebhookReceipt;
use App\Models\User;
use App\Support\TatumWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCryptoMonitor;
use Tests\TestCase;

class TatumWebhookTest extends TestCase
{
    use CreatesCryptoMonitor;
    use RefreshDatabase;

    public function test_valid_signed_hint_is_persisted_once_and_never_marks_paid_or_credits_balances(): void
    {
        $monitor = $this->createCryptoMonitor();
        Http::preventStrayRequests();
        $raw = json_encode($this->payload($monitor), JSON_UNESCAPED_SLASHES);

        $this->deliver($monitor, $raw)->assertStatus(202)->assertJsonPath('message', 'accepted for verification');
        $this->deliver($monitor, $raw)->assertStatus(202);

        $receipt = CryptoWebhookReceipt::query()->sole();
        $this->assertSame($this->merchant->id, $receipt->merchant_id);
        $this->assertSame('pending', $receipt->status);
        $this->assertSame(str_repeat('c', 64), $receipt->transaction_hash);
        $this->assertSame(hash('sha256', $raw), $receipt->payload_hash);
        $this->assertSame('preparing', $monitor->paymentRequest->status);
        $this->assertSame('pending', $monitor->paymentRequest->order->status);
        $this->assertDatabaseCount('crypto_webhook_receipts', 1);
        $this->assertDatabaseCount('crypto_chain_events', 0);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
        $this->assertDatabaseCount('merchant_balance_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_payload_tenant_id_and_unrecognized_private_fields_are_not_trusted_or_saved(): void
    {
        $monitor = $this->createCryptoMonitor();
        $payload = [...$this->payload($monitor), 'merchant_id' => $this->merchant->id + 1, 'private_key' => 'must-not-store'];

        $this->deliver($monitor, json_encode($payload))->assertStatus(202);

        $receipt = CryptoWebhookReceipt::query()->sole();
        $this->assertSame($this->merchant->id, $receipt->merchant_id);
        $this->assertArrayNotHasKey('merchant_id', $receipt->hint_payload);
        $this->assertArrayNotHasKey('private_key', $receipt->hint_payload);
    }

    public function test_provider_delivery_uses_bound_merchant_even_if_a_different_browser_user_is_authenticated(): void
    {
        $monitor = $this->createCryptoMonitor();
        $raw = json_encode($this->payload($monitor));
        $this->actingAs(User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]));

        $this->deliver($monitor, $raw)->assertStatus(202);

        $this->assertSame($this->merchant->id, CryptoWebhookReceipt::query()->withoutGlobalScopes()->sole()->merchant_id);
    }

    public function test_missing_or_tampered_signature_returns_401_without_receipt(): void
    {
        $monitor = $this->createCryptoMonitor();
        $raw = json_encode($this->payload($monitor));
        $this->call('POST', $this->path($monitor), [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(401);
        $signature = base64_encode(hash_hmac('sha512', $raw, config('services.tatum.testnet_hmac_secret'), true));

        $this->call('POST', $this->path($monitor), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_HASH' => $signature], str_replace('999', '998', $raw))->assertStatus(401);

        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public function test_mainnet_secret_cannot_authenticate_testnet_callback(): void
    {
        $monitor = $this->createCryptoMonitor();
        $raw = json_encode($this->payload($monitor));
        $signature = base64_encode(hash_hmac('sha512', $raw, config('services.tatum.mainnet_hmac_secret'), true));

        $this->call('POST', $this->path($monitor), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_HASH' => $signature], $raw)->assertStatus(401);

        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    #[DataProvider('invalidBodies')]
    public function test_valid_hmac_does_not_make_invalid_json_or_oversized_body_acceptable(string $body, int $status): void
    {
        $monitor = $this->createCryptoMonitor();

        $this->deliver($monitor, $body)->assertStatus($status);

        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public static function invalidBodies(): array
    {
        return ['invalid JSON' => ['{broken', 400], 'array' => ['[]', 400], 'too large' => [str_repeat('x', 65537), 413]];
    }

    public function test_wrong_chain_or_address_returns_422_even_with_valid_signature(): void
    {
        $monitor = $this->createCryptoMonitor();
        $payload = $this->payload($monitor);
        $payload['chain'] = 'ethereum-mainnet';
        $this->deliver($monitor, json_encode($payload))->assertStatus(422);
        $payload['chain'] = 'ethereum-sepolia';
        $payload['address'] = '0x'.str_repeat('f', 40);

        $this->deliver($monitor, json_encode($payload))->assertStatus(422);

        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public function test_enriched_outgoing_hint_and_late_cold_callback_are_kept_without_assuming_income(): void
    {
        $monitor = $this->createCryptoMonitor();
        $monitor->update(['status' => 'cold', 'cancelled_at' => now()]);
        $payload = ['data' => ['txId' => str_repeat('c', 64), 'subscriptionType' => 'ADDRESS_EVENT', 'from' => $monitor->address->address,
            'to' => '0x'.str_repeat('b', 40), 'value' => '999999999999999999999999999999999999999999999999']];

        $this->deliver($monitor, json_encode($payload))->assertStatus(202);

        $this->assertSame($payload['data']['value'], CryptoWebhookReceipt::query()->sole()->hint_payload['value']);
        $this->assertDatabaseCount('crypto_ledger_entries', 0);
    }

    public function test_unknown_subscription_type_is_ignored(): void
    {
        $monitor = $this->createCryptoMonitor();

        $this->deliver($monitor, json_encode([...$this->payload($monitor), 'subscriptionType' => 'PRICE_CHANGE']))
            ->assertStatus(202)->assertJsonPath('message', 'ignored');

        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public function test_disabled_receiver_and_unknown_monitor_do_not_create_receipts(): void
    {
        $monitor = $this->createCryptoMonitor();
        config(['services.tatum.subscriptions_enabled' => false]);
        $this->deliver($monitor, json_encode($this->payload($monitor)))->assertStatus(503);
        config(['services.tatum.subscriptions_enabled' => true]);
        $raw = json_encode($this->payload($monitor));
        $signature = base64_encode(hash_hmac('sha512', $raw, config('services.tatum.testnet_hmac_secret'), true));
        $this->call('POST', '/api/webhooks/tatum/testnet/11111111-1111-4111-8111-111111111111', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_HASH' => $signature], $raw)->assertNotFound();
        $this->assertDatabaseCount('crypto_webhook_receipts', 0);
    }

    public function test_official_hmac_vector_and_whitespace_policy_preserve_strings_and_integer_lexemes(): void
    {
        config(['services.tatum.testnet_hmac_secret' => 'c354b83b-d31b-4dda-9bab-d6a67715a1ed']);
        $raw = '{"address":"TJG7iciLGjsib9qhe6U6F7M2vxYJuDjWNM","amount":"20","counterAddress":"TVf3RVEtzKtMfqQaCAWs9d4HKbC4bZGaWP","asset":"TRON","blockNumber":44087791,"txId":"93442189d7bccbe009f8ab594831ff9d7d258cab712d74a404cd3dccdc4c6d69","type":"native","tokenId":null,"chain":"tron-testnet","subscriptionType":"ADDRESS_EVENT"}';
        $header = 'WdhYQft+qP8LpYAdeOMncUzIZ7DSUWX9JVSjeGH3F4mCreUxtIpTl2VYigm+qUvkfSQ0lWmTrzADm4mGxSVcxA==';
        $verifier = app(TatumWebhookSignature::class);

        $this->assertSame('raw', $verifier->verify($raw, $header, 'testnet'));
        $this->assertSame('json_whitespace_v1', $verifier->verify(str_replace(',', ",\n ", $raw), $header, 'testnet'));
        $this->assertNull($verifier->verify(str_replace('44087791', '4.4087791e7', $raw), $header, 'testnet'));
    }

    public function test_signature_compaction_preserves_escaped_quotes_string_spaces_and_large_numeric_tokens(): void
    {
        config(['services.tatum.testnet_hmac_secret' => 'test-testnet-webhook-secret-123456']);
        $raw = '{"blockNumber":123456789012345678901234567890,"note":"keep  space and \\" quote"}';
        $header = base64_encode(hash_hmac('sha512', $raw, config('services.tatum.testnet_hmac_secret'), true));
        $pretty = str_replace('{', "{\n  ", $raw);
        $verifier = app(TatumWebhookSignature::class);

        $this->assertSame('json_whitespace_v1', $verifier->verify($pretty, $header, 'testnet'));
        $this->assertNull($verifier->verify(str_replace('keep  space', 'keepspace', $pretty), $header, 'testnet'));
    }

    private function payload(CryptoAddressMonitor $monitor): array
    {
        return ['chain' => $monitor->network, 'address' => $monitor->address->address, 'subscriptionType' => 'ADDRESS_EVENT',
            'txId' => '0x'.str_repeat('c', 64), 'amount' => '999.5', 'asset' => 'ETH', 'type' => 'native'];
    }

    private function path(CryptoAddressMonitor $monitor): string
    {
        return '/api/webhooks/tatum/'.$monitor->environment.'/'.$monitor->callback_uuid;
    }

    private function deliver(CryptoAddressMonitor $monitor, string $raw): TestResponse
    {
        $header = base64_encode(hash_hmac('sha512', $raw, config('services.tatum.testnet_hmac_secret'), true));

        return $this->call('POST', $this->path($monitor), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYLOAD_HASH' => $header], $raw);
    }
}

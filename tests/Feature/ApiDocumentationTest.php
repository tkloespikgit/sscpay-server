<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    /** @var list<string> */
    private const ANCHORS = [
        'overview', 'authentication', 'create-order', 'query-order', 'upload-shipping',
        'merchant-shipment-sync', 'payment-notifications', 'error-codes',
        'reference-currencies', 'reference-platforms', 'order-statuses',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    #[DataProvider('documentationVariants')]
    public function test_public_documentation_renders_the_four_interfaces_and_valid_navigation_in_both_languages(string $locale, string $htmlLocale, string $version): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $response = $this->get(route('api.documentation', ['locale' => $locale, 'version' => $version]));

        $response->assertOk()->assertSeeHtml('<html lang="'.$htmlLocale.'"');
        foreach (['/api/order/create', '/api/order/query', '/api/order/ship', '/api/fulfillment/shipment',
            'App-ID', 'Timestamp', 'X-Nonce', 'HMAC-SHA256', 'merchant_order_no', 'sign'] as $text) {
            $response->assertSee($text);
        }
        $xpath = $this->document($response->getContent());
        foreach (self::ANCHORS as $anchor) {
            $this->assertSame(1, $xpath->query('//*[@id="'.$anchor.'"]')->length, 'Missing or duplicate section '.$anchor);
            $this->assertGreaterThan(0, $xpath->query('//nav//a[@href="#'.$anchor.'"]')->length, 'Missing navigation to '.$anchor);
        }
        foreach ($xpath->query('//a[starts-with(@href, "#")]') as $link) {
            $fragment = substr($link->getAttribute('href'), 1);
            $this->assertSame(1, $xpath->query('//*[@id="'.$fragment.'"]')->length, 'Broken anchor '.$fragment);
        }
        foreach (['zh_CN' => 'zh-CN', 'en' => 'en'] as $language => $hreflang) {
            $links = $xpath->query('//a[@data-language-link and @href="'.route('api.documentation', ['locale' => $language, 'version' => $version]).'"]');
            $this->assertSame(1, $links->length);
            $this->assertSame($hreflang, $links->item(0)->getAttribute('hreflang'));
        }
        foreach (['2.0', '1.0'] as $availableVersion) {
            $links = $xpath->query('//a[@data-version-link and @href="'.route('api.documentation', ['locale' => $locale, 'version' => $availableVersion]).'"]');
            $this->assertSame(1, $links->length);
            $this->assertSame($availableVersion === $version ? 'page' : '', $links->item(0)->getAttribute('aria-current'));
        }
        $canonical = $xpath->query('//link[@rel="canonical"]')->item(0);
        $this->assertSame(route('api.documentation', ['locale' => $locale, 'version' => $version]), $canonical->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//header//nav[.//a[@data-language-link]]/following-sibling::details[@data-version-switcher]')->length);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('documentationVariants')]
    public function test_rendered_json_examples_are_valid_and_the_four_requests_have_the_required_fields(string $locale, string $htmlLocale, string $version): void
    {
        $response = $this->get(route('api.documentation', ['locale' => $locale, 'version' => $version]));
        $response->assertOk();
        $xpath = $this->document($response->getContent());
        $examples = $xpath->query('//pre/code[@data-language="json"]');
        $this->assertGreaterThanOrEqual(8, $examples->length);
        foreach ($examples as $example) {
            $this->assertIsArray(json_decode($example->textContent, true, flags: JSON_THROW_ON_ERROR));
        }
        $requiredFields = [
            'create-order' => [
                'merchant_order_no', 'currency', 'platform', 'group_key', 'subtotal', 'shipping_fee', 'discount', 'tax', 'amount',
                'customer.first_name', 'customer.last_name', 'customer.email',
                'shipping_address.line1', 'shipping_address.city', 'shipping_address.country',
                'notify_url', 'return_url', 'cancel_url',
                'items.0.product_id', 'items.0.product_url', 'items.0.product_name', 'items.0.unit_price', 'items.0.quantity', 'sign',
            ],
            'query-order' => ['merchant_order_no', 'sign'],
            'upload-shipping' => ['merchant_order_no', 'logistics_company', 'tracking_number', 'shipped_at', 'sign'],
            'merchant-shipment-sync' => ['merchant_order_no', 'carrier', 'tracking_number', 'shipped_at', 'sign'],
        ];
        foreach ($requiredFields as $section => $fields) {
            $example = $xpath->query('//*[@id="'.$section.'"]//pre/code[@data-language="json"]')->item(0);
            $this->assertNotNull($example, 'Missing request example for '.$section);
            $request = json_decode($example->textContent, true, flags: JSON_THROW_ON_ERROR);
            foreach ($fields as $field) {
                $this->assertTrue(Arr::has($request, $field), $section.' request omits '.$field);
            }
            $this->assertIsString($request['merchant_order_no']);
            $this->assertIsString($request['sign']);
        }
    }

    #[DataProvider('translationGroups')]
    public function test_translation_sections_use_the_same_stable_anchors_and_have_no_missing_labels(string $group): void
    {
        $chinese = Lang::get($group, [], 'zh_CN');
        $english = Lang::get($group, [], 'en');

        $this->assertIsArray($chinese);
        $this->assertIsArray($english);
        $this->assertSame(self::ANCHORS, array_keys($chinese['sections']));
        $this->assertSame(self::ANCHORS, array_keys($english['sections']));
        foreach (self::ANCHORS as $anchor) {
            $this->assertNotEmpty($chinese['sections'][$anchor]['title']);
            $this->assertNotEmpty($english['sections'][$anchor]['title']);
        }
    }

    #[DataProvider('unsupportedLocales')]
    public function test_unsupported_language_paths_return_not_found(string $locale): void
    {
        $this->get('/docs/api/'.$locale)->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function unsupportedLocales(): array
    {
        return ['unsupported language' => ['fr'], 'unexpected chinese alias' => ['zh'], 'wrong case' => ['EN']];
    }

    #[DataProvider('preferredLocales')]
    public function test_the_default_page_uses_a_supported_session_locale_then_the_configured_locale(mixed $sessionLocale, string $configuredLocale, string $expectedLocale): void
    {
        config(['app.locale' => $configuredLocale]);

        $response = $this->withSession(['locale' => $sessionLocale])->get(route('api.documentation'));

        $response->assertOk()->assertViewHas('locale', $expectedLocale)->assertViewHas('version', '2.0')
            ->assertSessionHas('locale', fn (mixed $value): bool => $value === $sessionLocale);
    }

    /** @return array<string, array{mixed, string, string}> */
    public static function preferredLocales(): array
    {
        return [
            'english session overrides chinese config' => ['en', 'zh_CN', 'en'],
            'chinese session overrides english config' => ['zh_CN', 'en', 'zh_CN'],
            'no session uses english config' => [null, 'en', 'en'],
            'invalid session uses valid config' => ['fr', 'en', 'en'],
            'invalid session and config use chinese' => ['fr', 'de', 'zh_CN'],
        ];
    }

    public function test_explicit_documentation_language_does_not_change_the_backend_session_or_application_locale(): void
    {
        $this->app->setLocale('zh_CN');

        $response = $this->withSession(['locale' => 'zh_CN'])->get(route('api.documentation', ['locale' => 'en']));

        $response->assertOk()->assertViewHas('locale', 'en')->assertSessionHas('locale', 'zh_CN');
        $this->assertSame('zh_CN', $this->app->getLocale());
    }

    public function test_documentation_neither_loads_private_application_data_nor_executes_business_operations(): void
    {
        $this->createMerchantAndApplication();
        $apiKey = $this->application->api_key;
        $appId = $this->application->app_id;
        $this->application->update([
            'name' => 'Private Merchant Application Name',
            'shipment_sync_url' => 'https://private-merchant.example.com/sync?token=private-query-token',
        ]);
        Http::preventStrayRequests();
        Queue::fake();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $response = $this->get(route('api.documentation', ['locale' => 'en']));

        $response->assertOk()->assertDontSee($apiKey)->assertDontSee($appId)
            ->assertDontSee('Private Merchant Application Name')->assertDontSee('private-query-token');
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select .* from ["`]?system_configs["`]? where /i', $query['sql']);
            $this->assertCount(1, $query['bindings']);
            $this->assertContains($query['bindings'][0], ['exchange.supported_currencies', 'order.platforms']);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{string, string}> */
    public static function locales(): array
    {
        return ['chinese' => ['zh_CN', 'zh-CN'], 'english' => ['en', 'en']];
    }

    /** @return array<string, array{string, string, string}> */
    public static function documentationVariants(): array
    {
        return [
            'chinese 1.0' => ['zh_CN', 'zh-CN', '1.0'], 'english 1.0' => ['en', 'en', '1.0'],
            'chinese 2.0' => ['zh_CN', 'zh-CN', '2.0'], 'english 2.0' => ['en', 'en', '2.0'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function translationGroups(): array
    {
        return ['1.0' => ['api_docs_v1'], '2.0' => ['api_docs_v2']];
    }

    #[DataProvider('locales')]
    public function test_version_one_excludes_combined_fields_examples_and_progress_notifications(string $locale): void
    {
        $response = $this->get(route('api.documentation', ['locale' => $locale, 'version' => '1.0']));
        $response->assertOk()->assertViewHas('version', '1.0');
        $xpath = $this->document($response->getContent());
        $body = $xpath->query('//main')->item(0)->textContent;
        foreach (['order_type', 'parent_order_no', 'event_id', 'paid_amount', 'children', 'COMBINE_AMOUNT_LIMIT_EXCEEDED', 'CHILD_ORDER_REQUIRED', '-S001', 'V2'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }
        $notifications = $xpath->query('//*[@id="payment-notifications"]//pre/code[@data-language="json"]');
        $this->assertSame(1, $notifications->length);
        $payload = json_decode($notifications->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('paid', $payload['status']);
        $this->assertArrayNotHasKey('order_type', $payload);
    }

    #[DataProvider('locales')]
    public function test_version_two_uses_v2_for_both_single_and_combined_notification_examples(string $locale): void
    {
        $response = $this->get(route('api.documentation', ['locale' => $locale]));
        $response->assertOk()->assertViewHas('version', '2.0');
        $xpath = $this->document($response->getContent());
        $this->assertStringNotContainsString('V1', $xpath->query('//main')->item(0)->textContent);
        $notifications = $xpath->query('//*[@id="payment-notifications"]//pre/code[@data-language="json"]');
        $this->assertSame(2, $notifications->length);
        $single = json_decode($notifications->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $combined = json_decode($notifications->item(1)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('single', $single['order_type']);
        $this->assertSame('190.00', $single['paid_amount']);
        $this->assertSame('0.00', $single['remaining_amount']);
        $this->assertSame([], $single['children']);
        $this->assertSame('combine', $combined['order_type']);
        $this->assertSame('pending', $combined['status']);
        $this->assertCount(1, $combined['children']);
        foreach ([$single, $combined] as $payload) {
            $this->assertArrayHasKey('event_id', $payload);
            $this->assertSame(1, $payload['version']);
        }
    }

    #[DataProvider('unsupportedVersions')]
    public function test_unknown_or_malformed_versions_return_not_found(mixed $version): void
    {
        $this->get(route('api.documentation', ['locale' => 'en', 'version' => $version]))->assertNotFound();
    }

    /** @return array<string, array{mixed}> */
    public static function unsupportedVersions(): array
    {
        return ['unknown' => ['3.0'], 'unsupported alias' => ['v1'], 'empty' => [''], 'array' => [['1.0']], 'path' => ['../api_docs']];
    }

    private function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8" ?>'.$html, LIBXML_NONET);

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

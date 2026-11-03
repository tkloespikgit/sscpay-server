<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SystemConfig;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiDocumentationReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    #[DataProvider('locales')]
    public function test_reference_tables_show_configured_codes_with_localized_names_in_each_language(string $locale): void
    {
        SystemConfig::set('exchange.supported_currencies', ['USD', 'CNY', 'CUSTOM-CURRENCY'], 'exchange');
        SystemConfig::set('order.platforms', ['wordpress', 'invoice', 'custom-store'], 'order');
        Http::preventStrayRequests();
        Queue::fake();

        $response = $this->get(route('api.documentation', ['locale' => $locale]));

        $response->assertOk();
        $currencyNames = Lang::get('api_docs.reference.currency_names', [], $locale);
        $platformNames = Lang::get('api_docs.reference.platform_names', [], $locale);
        $this->assertSame([
            ['USD', $currencyNames['USD']],
            ['CNY', $currencyNames['CNY']],
            ['CUSTOM-CURRENCY', Lang::get('api_docs.reference.unknown_currency', [], $locale)],
        ], $this->rows($response->getContent(), 'reference-currencies'));
        $this->assertSame([
            ['wordpress', $platformNames['wordpress']],
            ['invoice', $platformNames['invoice']],
            ['custom-store', Lang::get('api_docs.reference.unknown_platform', [], $locale)],
        ], $this->rows($response->getContent(), 'reference-platforms'));
        $this->assertSame(Order::supportedPlatforms(), array_column($this->rows($response->getContent(), 'reference-platforms'), 0));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_updated_system_configuration_is_reflected_on_the_next_request_without_waiting_for_the_cache_ttl(): void
    {
        SystemConfig::set('exchange.supported_currencies', ['USD', 'EUR'], 'exchange');
        SystemConfig::set('order.platforms', ['wordpress'], 'order');
        $first = $this->get(route('api.documentation', ['locale' => 'en']))->assertOk();
        $this->assertSame(['USD', 'EUR'], array_column($this->rows($first->getContent(), 'reference-currencies'), 0));
        $this->assertSame(['wordpress'], array_column($this->rows($first->getContent(), 'reference-platforms'), 0));

        SystemConfig::set('exchange.supported_currencies', ['USD', 'JPY', 'CAD'], 'exchange');
        SystemConfig::set('order.platforms', ['shopline', 'invoice'], 'order');
        $updated = $this->get(route('api.documentation', ['locale' => 'en']))->assertOk();

        $this->assertSame(['USD', 'JPY', 'CAD'], array_column($this->rows($updated->getContent(), 'reference-currencies'), 0));
        $this->assertSame(['shopline', 'invoice'], array_column($this->rows($updated->getContent(), 'reference-platforms'), 0));
    }

    public function test_missing_configuration_uses_the_same_currency_and_platform_defaults_as_the_application(): void
    {
        SystemConfig::query()->whereIn('config_key', ['exchange.supported_currencies', 'order.platforms'])->delete();
        Cache::forget('system_config:exchange.supported_currencies');
        Cache::forget('system_config:order.platforms');

        $response = $this->get(route('api.documentation', ['locale' => 'en']))->assertOk();

        $this->assertSame(['USD', 'EUR', 'JPY', 'GBP'], array_column($this->rows($response->getContent(), 'reference-currencies'), 0));
        $this->assertSame(Order::PLATFORMS_FALLBACK, array_column($this->rows($response->getContent(), 'reference-platforms'), 0));
    }

    #[DataProvider('locales')]
    public function test_explicit_empty_configuration_displays_localized_empty_messages_and_does_not_advertise_default_values(string $locale): void
    {
        SystemConfig::set('exchange.supported_currencies', [], 'exchange');
        SystemConfig::set('order.platforms', [], 'order');

        $response = $this->get(route('api.documentation', ['locale' => $locale]))->assertOk();

        $this->assertSame([], $this->rows($response->getContent(), 'reference-currencies'));
        $this->assertSame([], $this->rows($response->getContent(), 'reference-platforms'));
        $xpath = $this->document($response->getContent());
        $currencySection = $xpath->query('//*[@id="reference-currencies"]')->item(0)->textContent;
        $platformSection = $xpath->query('//*[@id="reference-platforms"]')->item(0)->textContent;
        $this->assertStringContainsString(Lang::get('api_docs.reference.empty_currencies', [], $locale), $currencySection);
        $this->assertStringContainsString(Lang::get('api_docs.reference.empty_platforms', [], $locale), $platformSection);
        $this->assertSame([], Order::supportedPlatforms());
    }

    #[DataProvider('locales')]
    public function test_the_status_appendix_keeps_its_existing_anchor_and_contains_every_application_order_status(string $locale): void
    {
        $response = $this->get(route('api.documentation', ['locale' => $locale]))->assertOk();
        $rows = $this->rows($response->getContent(), 'order-statuses');
        $expected = array_keys(Lang::get('admin.order.statuses', [], $locale));
        $actual = array_column($rows, 0);
        sort($expected);
        sort($actual);

        $this->assertCount(12, $rows);
        $this->assertSame($expected, $actual);
        $xpath = $this->document($response->getContent());
        $sections = $xpath->query('//main//section[@id]');
        $this->assertSame('order-statuses', $sections->item($sections->length - 1)->getAttribute('id'));
        $this->assertSame(1, $xpath->query('//*[@id="order-statuses"]')->length);
    }

    #[DataProvider('locales')]
    public function test_body_reference_links_point_to_the_corresponding_currency_platform_and_status_appendices(string $locale): void
    {
        $response = $this->get(route('api.documentation', ['locale' => $locale]))->assertOk();
        $xpath = $this->document($response->getContent());
        $references = [
            'overview' => ['reference-currencies', 'reference-platforms', 'order-statuses'],
            'create-order' => ['reference-currencies', 'reference-platforms', 'order-statuses'],
            'query-order' => ['order-statuses'],
            'upload-shipping' => ['order-statuses'],
            'payment-notifications' => ['order-statuses'],
        ];

        foreach ($references as $section => $anchors) {
            foreach ($anchors as $anchor) {
                $this->assertGreaterThan(0, $xpath->query('//*[@id="'.$section.'"]//a[@href="#'.$anchor.'"]')->length,
                    $section.' is missing its body reference to '.$anchor);
                $this->assertSame(1, $xpath->query('//*[@id="'.$anchor.'"]')->length);
            }
        }
    }

    public function test_configured_codes_are_preserved_as_text_and_cannot_inject_markup_into_the_public_documentation(): void
    {
        $currency = '<img src=x onerror="alert(1)">';
        $platform = '<script>alert("platform")</script>';
        SystemConfig::set('exchange.supported_currencies', [$currency], 'exchange');
        SystemConfig::set('order.platforms', [$platform], 'order');

        $response = $this->get(route('api.documentation', ['locale' => 'en']))->assertOk();

        $response->assertSee($currency)->assertSee($platform)->assertDontSeeHtml($currency)->assertDontSeeHtml($platform);
        $this->assertSame($currency, $this->rows($response->getContent(), 'reference-currencies')[0][0]);
        $this->assertSame($platform, $this->rows($response->getContent(), 'reference-platforms')[0][0]);
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//*[@id="reference-currencies"]//*[@onerror]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="reference-platforms"]//script')->length);
    }

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return ['chinese' => ['zh_CN'], 'english' => ['en']];
    }

    /** @return list<list<string>> */
    private function rows(string $html, string $section): array
    {
        $xpath = $this->document($html);
        $rows = [];
        foreach ($xpath->query('//*[@id="'.$section.'"]//table/tbody/tr') as $row) {
            $cells = [];
            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim($cell->textContent);
            }
            $rows[] = $cells;
        }

        return $rows;
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

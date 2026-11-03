<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApplicationResource\Pages\CreateApplication;
use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Models\User;
use App\Support\Permissions;
use DOMDocument;
use DOMXPath;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ApiDocumentationLinkTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    #[DataProvider('locales')]
    public function test_the_application_list_links_to_documentation_in_the_current_language_in_a_new_tab(string $locale, string $documentLocale): void
    {
        $this->createMerchantAndApplication();
        $this->app->setLocale($locale);
        Http::preventStrayRequests();
        Queue::fake();

        Livewire::actingAs($this->administrator())
            ->test(ListApplications::class)
            ->assertOk()
            ->assertActionVisible(TestAction::make('apiDocumentation')->table())
            ->assertActionHasUrl(TestAction::make('apiDocumentation')->table(), route('api.documentation', ['locale' => $documentLocale]))
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('apiDocumentation')->table());

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('locales')]
    public function test_the_application_edit_form_renders_a_documentation_link_without_submitting_or_changing_the_application(string $locale, string $documentLocale): void
    {
        $this->createMerchantAndApplication();
        $this->app->setLocale($locale);
        $before = $this->application->fresh()->getRawOriginal();
        Http::preventStrayRequests();
        Queue::fake();

        $page = Livewire::actingAs($this->administrator())
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertOk();

        $this->assertDocumentationLink($page->html(), $documentLocale);
        $this->assertSame($before, $this->application->fresh()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_the_application_creation_form_also_exposes_the_documentation_link(): void
    {
        $this->createMerchantAndApplication();
        $this->app->setLocale('en');
        Http::preventStrayRequests();
        Queue::fake();

        $page = Livewire::actingAs($this->administrator())
            ->test(CreateApplication::class)
            ->assertOk();

        $this->assertDocumentationLink($page->html(), 'en');
        $this->assertDatabaseCount('applications', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{string, string}> */
    public static function locales(): array
    {
        return ['chinese' => ['zh_CN', 'zh_CN'], 'english' => ['en', 'en'], 'unsupported locale defaults to chinese' => ['fr', 'zh_CN']];
    }

    private function assertDocumentationLink(string $html, string $locale): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8" ?>'.$html, LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $links = $xpath->query('//a[@href="'.route('api.documentation', ['locale' => $locale]).'" and @target="_blank"]');
            $this->assertSame(1, $links->length);
            $this->assertNotEmpty(trim($links->item(0)->textContent));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function administrator(): User
    {
        $user = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::APPLICATIONS_MANAGE);

        return $user;
    }
}

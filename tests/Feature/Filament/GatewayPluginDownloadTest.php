<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodResource;
use App\Models\User;
use App\Services\PluginCatalog;
use App\Services\PluginReleaseService;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class GatewayPluginDownloadTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private string $buildPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildPath = sys_get_temp_dir().'/sscpay-gateway-download-'.Str::uuid();
        config(['plugins.disk' => 'gateway-download-test', 'plugins.build_path' => $this->buildPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->buildPath);
        parent::tearDown();
    }

    #[TestWith(['admin'])]
    #[TestWith(['merchant'])]
    public function test_create_and_edit_headers_link_to_the_panels_private_zip(string $panelId): void
    {
        Storage::fake('gateway-download-test');
        $this->createMerchantAndApplication();
        $record = $this->makePaymentMethod('gateway_download');
        $user = User::factory()->create([
            'merchant_id' => $panelId === 'merchant' ? $this->merchant->id : null,
            'status' => true,
            'is_super_admin' => $panelId === 'admin',
        ]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $this->actingAs($user);

        $releases = app(PluginReleaseService::class);
        $build = $releases->build(PluginCatalog::GATEWAY);
        $releases->publish($build);
        $release = $build['release'];
        $url = Filament::getPanel($panelId)->route('plugins.gateway.download');
        $label = __('admin.payment_method.actions.download_gateway_plugin');

        $this->get(PaymentMethodResource::getUrl('create', panel: $panelId))
            ->assertOk()->assertSee($label)->assertSeeHtml('href="'.$url.'"');
        $this->get(PaymentMethodResource::getUrl('edit', ['record' => $record], panel: $panelId))
            ->assertOk()->assertSee($label)->assertSeeHtml('href="'.$url.'"');

        $response = $this->get($url)->assertDownload($release['slug'].'-'.$release['version'].'.zip')
            ->assertHeader('X-Checksum-Sha256', $release['sha256']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame($release['sha256'], hash('sha256', $response->streamedContent()));
    }

    public function test_guest_and_accounts_without_panel_or_payment_method_access_cannot_download(): void
    {
        $panel = Filament::getPanel('admin');
        $url = $panel->route('plugins.gateway.download');
        $this->get($url)->assertRedirect($panel->getLoginUrl());

        $user = User::factory()->create(['merchant_id' => null, 'status' => true, 'is_super_admin' => false]);
        $this->actingAs($user)->get($url)->assertForbidden();

        $disabled = User::factory()->create(['merchant_id' => null, 'status' => false, 'is_super_admin' => true]);
        $this->actingAs($disabled)->get($url)->assertForbidden();
    }

    public function test_a_gateway_download_without_a_published_zip_returns_not_found(): void
    {
        Storage::fake('gateway-download-test');
        $user = User::factory()->create(['merchant_id' => null, 'status' => true, 'is_super_admin' => true]);

        $this->actingAs($user)
            ->get(Filament::getPanel('admin')->route('plugins.gateway.download'))
            ->assertNotFound();
    }
}

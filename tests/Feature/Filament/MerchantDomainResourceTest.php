<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MerchantDomainResource\Pages\CreateMerchantDomain;
use App\Filament\Resources\MerchantDomainResource\Pages\EditMerchantDomain;
use App\Filament\Resources\MerchantDomainResource\Pages\ListMerchantDomains;
use App\Models\Merchant;
use App\Models\MerchantDomain;
use App\Models\User;
use App\Services\Checkout\CloudflareSaasService;
use App\Services\Checkout\MerchantDomainService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 自有域名后台页面。这里重点不是业务逻辑，而是「页面能不能渲染出来」——
 * DNS 配置指引那一段用了 Filament\Schemas\Components\Text 并在闭包里读
 * $record，新建时 $record 为 null，写错就是一个白屏 500，而这恰恰是商户
 * 建完域名之后必然会打开的那一页。
 */
class MerchantDomainResourceTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMerchantAndApplication();

        Filament::setCurrentPanel('merchant');
        $this->actingAs($this->domainUser());
    }

    public function test_create_page_renders_without_a_record(): void
    {
        Livewire::test(CreateMerchantDomain::class)->assertOk();
    }

    public function test_creating_a_domain_normalises_the_host_and_issues_a_token(): void
    {
        Livewire::test(CreateMerchantDomain::class)
            ->fillForm([
                'merchant_id' => $this->merchant->id,
                // 商户常见的粘贴形式：带协议、大小写混用、结尾带斜杠。
                'host' => 'https://Checkout.TVBox.com/',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $domain = MerchantDomain::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('checkout.tvbox.com', $domain->host);
        $this->assertStringStartsWith('sscpay-verify=', $domain->verify_token);
        $this->assertSame('_sscpay-challenge.checkout.tvbox.com', $domain->verifyRecordName());
        // 还没验证、也没证书，不能被当成可用域名。
        $this->assertFalse($domain->isReady());
    }

    public function test_root_domain_is_rejected(): void
    {
        Livewire::test(CreateMerchantDomain::class)
            ->fillForm([
                'merchant_id' => $this->merchant->id,
                // 根域名加不了 CNAME，而本方案的接入方式正是 CNAME。
                'host' => 'tvbox.com',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['host']);

        $this->assertSame(0, MerchantDomain::withoutGlobalScopes()->count());
    }

    public function test_edit_page_renders_the_dns_instructions(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'cf_dcv_records' => [['type' => 'TXT', 'name' => '_acme-challenge.checkout.tvbox.com', 'value' => 'dcv-token']],
        ]);

        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->assertOk()
            ->assertSee('_sscpay-challenge.checkout.tvbox.com')
            ->assertSee('_acme-challenge.checkout.tvbox.com')
            ->assertSee('dcv-token');
    }

    public function test_changing_the_host_invalidates_previous_verification(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'verified_at' => now(),
            'cf_hostname_id' => 'cf-123',
            'cf_ssl_status' => MerchantDomain::CF_STATUS_ACTIVE,
        ]);

        $originalToken = $domain->verify_token;

        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response([], 200)]);

        $domain->update(['host' => 'pay.tvbox.com']);
        $domain->refresh();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/custom_hostnames/cf-123'));

        // 换了域名等于换了一个待验证对象，旧的验证结果与 CF 主机名都必须作废，
        // 否则会出现"验证的是 A 域名、实际服务的是 B 域名"。
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->cf_hostname_id);
        $this->assertNull($domain->cf_ssl_status);
        $this->assertNotSame($originalToken, $domain->verify_token);
    }

    public function test_edit_page_fetches_delayed_dcv_records_and_renders_them_without_reopening(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'verified_at' => now(),
        ]);
        $this->configureCloudflare();
        Http::preventStrayRequests();
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => true, 'result' => ['id' => 'cf-123', 'ssl' => ['status' => 'pending_validation']]])
            ->push(['success' => true, 'result' => ['id' => 'cf-123', 'ssl' => [
                'status' => 'pending_validation',
                'txt_name' => '_acme-challenge.checkout.tvbox.com',
                'txt_value' => 'first-certificate-token',
                'validation_records' => [
                    ['txt_name' => '_acme-challenge.checkout.tvbox.com', 'txt_value' => 'first-certificate-token'],
                    ['txt_name' => '_acme-challenge.checkout.tvbox.com', 'txt_record' => 'second-certificate-token'],
                ],
                'dcv_delegation_records' => [['cname' => '_acme-challenge.checkout.tvbox.com', 'cname_target' => 'alternative.example.com']],
            ]]])
            ->push(['success' => true, 'result' => ['id' => 'cf-123', 'ssl' => ['status' => 'active']]])]);

        $page = Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee(__('admin.merchant_domain.help.dcv_pending'))
            ->callAction('verify')
            ->assertSee(__('admin.merchant_domain.help.dcv_pending'))
            ->assertSee('pending_validation');

        $this->assertSame('cf-123', $domain->fresh()->cf_hostname_id);
        $this->assertNull($domain->fresh()->cf_dcv_records);

        $page->fillForm(['is_active' => false])
            ->callAction('verify')
            ->assertSee('_acme-challenge.checkout.tvbox.com')
            ->assertSee('first-certificate-token')
            ->assertSee('second-certificate-token')
            ->assertDontSee(__('admin.merchant_domain.help.dcv_pending'))
            ->assertFormSet(['is_active' => false]);

        $this->assertCount(2, $domain->fresh()->cf_dcv_records);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/custom_hostnames/cf-123'));

        $page->callAction('verify')->assertSee(__('admin.merchant_domain.help.dcv_active'));
        Http::assertSentCount(3);
    }

    public function test_second_step_is_visible_before_ownership_verification(): void
    {
        $domain = MerchantDomain::create(['merchant_id' => $this->merchant->id, 'host' => 'checkout.tvbox.com']);

        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee(__('admin.merchant_domain.help.dcv_before_ownership'));
    }

    public function test_refresh_reports_cloudflare_errors_in_the_second_step(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'verified_at' => now(),
            'cf_hostname_id' => 'cf-123',
        ]);
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => false, 'errors' => [['code' => 1000, 'message' => 'DCV API unavailable']],
        ], 200)]);

        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->callAction('verify')->assertSee('DCV API unavailable');
        $this->assertSame('cf-123', $domain->fresh()->cf_hostname_id);
    }

    public function test_changed_hostname_must_be_saved_before_refreshing(): void
    {
        $domain = MerchantDomain::create(['merchant_id' => $this->merchant->id, 'host' => 'checkout.tvbox.com']);
        Http::fake();

        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['host' => 'changed.tvbox.com'])->callAction('verify')
            ->assertHasErrors(['data.host']);

        Http::assertNothingSent();
        $this->assertSame('checkout.tvbox.com', $domain->fresh()->host);
    }

    public function test_cloudflare_refresh_parses_cname_records_and_clears_stale_records_on_404(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'verified_at' => now(),
            'cf_hostname_id' => 'cf-123',
        ]);
        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => true, 'result' => ['id' => 'cf-123', 'ssl' => [
                'status' => 'pending_validation',
                'validation_records' => [['cname' => '_acme-challenge.checkout.tvbox.com', 'cname_target' => 'validation.example.com']],
            ]]])
            ->push([], 404)]);

        app(CloudflareSaasService::class)->refresh($domain);
        $this->assertSame([['type' => 'CNAME', 'name' => '_acme-challenge.checkout.tvbox.com', 'value' => 'validation.example.com']], $domain->fresh()->cf_dcv_records);
        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->assertSee('validation.example.com')->assertSee('CNAME');

        app(CloudflareSaasService::class)->refresh($domain);
        $this->assertNull($domain->fresh()->cf_hostname_id);
        $this->assertNull($domain->fresh()->cf_dcv_records);
    }

    public function test_failed_cloudflare_deletion_keeps_the_original_host_and_id(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'cf_hostname_id' => 'cf-123',
        ]);

        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['errors' => [['message' => 'temporary failure']]], 500)]);

        try {
            $domain->update(['host' => 'pay.tvbox.com']);
            $this->fail('Changing the host should fail when Cloudflare deletion fails.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('temporary failure', $e->getMessage());
        }

        $domain->refresh();
        $this->assertSame('checkout.tvbox.com', $domain->host);
        $this->assertSame('cf-123', $domain->cf_hostname_id);
    }

    public function test_edit_page_stays_open_when_cloudflare_deletion_fails(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
            'cf_hostname_id' => 'cf-123',
        ]);

        $this->configureCloudflare();
        Http::fake(['api.cloudflare.com/*' => Http::response(['errors' => [['message' => 'temporary failure']]], 500)]);

        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()])
            ->fillForm(['host' => 'pay.tvbox.com'])
            ->call('save')
            ->assertOk();

        $this->assertSame('checkout.tvbox.com', $domain->fresh()->host);
    }

    private function configureCloudflare(): void
    {
        config()->set('services.cloudflare.api_token', 'test-token');
        config()->set('services.cloudflare.zone_id', 'test-zone');
        config()->set('services.cloudflare.fallback_origin', 'link.example.com');
    }

    public function test_list_page_renders(): void
    {
        MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
        ]);

        Livewire::test(ListMerchantDomains::class)
            ->assertOk()
            ->assertSee('checkout.tvbox.com');
    }

    public function test_order_administrator_can_verify_their_domain(): void
    {
        $domain = MerchantDomain::create([
            'merchant_id' => $this->merchant->id,
            'host' => 'checkout.tvbox.com',
        ]);
        $this->mock(MerchantDomainService::class)->shouldReceive('verify')->once()
            ->withArgs(fn (MerchantDomain $record) => $record->id === $domain->id)->andReturnTrue();

        Livewire::test(ListMerchantDomains::class)
            ->callTableAction('verify', $domain)
            ->assertNotified();
    }

    public function test_order_administrator_cannot_access_another_merchants_domain(): void
    {
        $other = Merchant::create([
            'name' => 'Other merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'other@example.com',
        ]);
        $domain = MerchantDomain::create(['merchant_id' => $other->id, 'host' => 'checkout.other.example']);

        Livewire::test(ListMerchantDomains::class)->assertCanNotSeeTableRecords([$domain]);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(EditMerchantDomain::class, ['record' => $domain->getRouteKey()]);
    }

    private function domainUser(): User
    {
        $user = User::create([
            'name' => 'Merchant User',
            'email' => 'domain-user@example.com',
            'password' => bcrypt('secret'),
            'merchant_id' => $this->merchant->id,
        ]);

        $user->assignRole(Role::where('merchant_id', $this->merchant->id)->where('name', '订单管理员')->firstOrFail());

        return $user->fresh();
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CheckoutLinkResource;
use App\Filament\Resources\CheckoutLinkResource\Pages\CreateCheckoutLink;
use App\Filament\Resources\CheckoutLinkResource\Pages\EditCheckoutLink;
use App\Filament\Resources\CheckoutLinkResource\Pages\ListCheckoutLinks;
use App\Filament\Resources\MerchantDomainResource;
use App\Filament\Resources\MerchantDomainResource\Pages\CreateMerchantDomain;
use App\Filament\Resources\MerchantDomainResource\Pages\EditMerchantDomain;
use App\Filament\Resources\MerchantDomainResource\Pages\ListMerchantDomains;
use App\Filament\Resources\ReplaceKeywordResource;
use App\Filament\Resources\ReplaceKeywordResource\Pages\CreateReplaceKeyword;
use App\Filament\Resources\ReplaceKeywordResource\Pages\EditReplaceKeyword;
use App\Filament\Resources\ReplaceKeywordResource\Pages\ListReplaceKeywords;
use App\Models\Application;
use App\Models\CheckoutLink;
use App\Models\Merchant;
use App\Models\MerchantDomain;
use App\Models\PaymentGroup;
use App\Models\ReplaceKeyword;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ResourceViewPermissionsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMerchantAndApplication();
        Filament::setCurrentPanel('merchant');
    }

    #[DataProvider('resources')]
    public function test_view_permission_opens_own_list_and_details_without_write_access(array $config): void
    {
        $own = $this->record($config['model'], $this->merchant);
        $other = $this->record($config['model'], Merchant::create(['name' => 'Other merchant']));
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo($config['view']);
        $this->actingAs($user);

        $this->assertTrue($config['resource']::canAccess());
        $page = Livewire::test($config['list'])->assertOk()
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('edit')->table($own))
            ->assertActionHidden(TestAction::make('delete')->table($own))
            ->mountAction(TestAction::make('view')->table($own))
            ->assertActionMounted(TestAction::make('view')->table($own))
            ->assertSchemaComponentStateSet($config['detail_field'], $own->getAttribute($config['detail_field']));
        $page->unmountAction()
            ->mountAction(TestAction::make('delete')->table($own))->assertActionNotMounted()
            ->call('callMountedAction')->assertActionNotMounted();

        $this->assertFalse($config['resource']::canView($other));
        $page->mountAction(TestAction::make('view')->table($other))->assertActionNotMounted();
        Livewire::test($config['create'])->assertForbidden();
        Livewire::test($config['edit'], ['record' => $own->id])->assertForbidden();
        $this->assertNotNull($own->fresh());
        $this->assertFalse($own->fresh()->trashed());
    }

    #[DataProvider('resources')]
    public function test_guest_unprivileged_and_disabled_users_cannot_open_the_list(array $config): void
    {
        Livewire::test($config['list'])->assertForbidden();
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $this->actingAs($user);
        $this->assertFalse($config['resource']::canAccess());
        Livewire::test($config['list'])->assertForbidden();

        $user->givePermissionTo($config['view']);
        $user->update(['status' => false]);
        $this->actingAs($user->fresh());
        Livewire::test($config['list'])->assertForbidden();
    }

    #[DataProvider('resources')]
    public function test_manage_permission_retains_read_and_write_access_with_tenant_isolation(array $config): void
    {
        $own = $this->record($config['model'], $this->merchant);
        $other = $this->record($config['model'], Merchant::create(['name' => 'Other merchant']));
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo($config['manage']);
        $this->actingAs($user);

        Livewire::test($config['list'])->assertOk()
            ->assertActionVisible('create')
            ->assertActionVisible(TestAction::make('view')->table($own))
            ->assertActionVisible(TestAction::make('edit')->table($own))
            ->assertActionVisible(TestAction::make('delete')->table($own));
        Livewire::test($config['create'])->assertOk();
        Livewire::test($config['edit'], ['record' => $own->id])->assertOk();
        $this->assertFalse($config['resource']::canView($other));
        $this->assertFalse($config['resource']::canEdit($other));
        $this->assertFalse($config['resource']::canDelete($other));
    }

    public function test_read_only_domain_user_cannot_verify_a_domain(): void
    {
        $domain = $this->record(MerchantDomain::class, $this->merchant);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::MERCHANT_DOMAINS_VIEW);
        $this->actingAs($user);
        Http::preventStrayRequests();

        Livewire::test(ListMerchantDomains::class)
            ->assertActionHidden(TestAction::make('verify')->table($domain))
            ->mountAction(TestAction::make('verify')->table($domain))->assertActionNotMounted()
            ->call('callMountedAction')->assertActionNotMounted();

        $this->assertNull($domain->fresh()->verified_at);
        Http::assertNothingSent();
    }

    #[DataProvider('resources')]
    public function test_revoking_manage_permission_blocks_an_already_open_delete_action(array $config): void
    {
        $record = $this->record($config['model'], $this->merchant);
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo([$config['view'], $config['manage']]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $page = Livewire::test($config['list'])
            ->mountAction(TestAction::make('delete')->table($record))
            ->assertActionMounted(TestAction::make('delete')->table($record));

        $user->revokePermissionTo($config['manage']);
        $this->actingAs($user->fresh());
        $page->call('callMountedAction')->assertSuccessful();

        $this->assertFalse($config['resource']::canDelete($record));
        $this->assertFalse($record->fresh()->trashed());
        Http::assertNothingSent();
    }

    #[DataProvider('resources')]
    public function test_platform_readers_only_see_assigned_merchants_and_super_admins_can_see_all(array $config): void
    {
        $manager = User::factory()->create(['merchant_id' => null, 'status' => true]);
        $manager->givePermissionTo($config['view']);
        $this->merchant->update(['owner_id' => $manager->id]);
        $own = $this->record($config['model'], $this->merchant);
        $other = $this->record($config['model'], Merchant::create(['name' => 'Other merchant']));
        Filament::setCurrentPanel('admin');

        Livewire::actingAs($manager)->test($config['list'])
            ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
        $this->assertFalse($config['resource']::canView($other));

        $admin = User::factory()->create(['merchant_id' => null, 'is_super_admin' => true, 'status' => true]);
        Livewire::actingAs($admin)->test($config['list'])
            ->assertCanSeeTableRecords([$own, $other])
            ->assertActionVisible(TestAction::make('edit')->table($other));
    }

    public function test_read_only_keyword_user_cannot_start_an_import(): void
    {
        $user = User::factory()->create(['merchant_id' => $this->merchant->id, 'status' => true]);
        $user->givePermissionTo(Permissions::REPLACE_KEYWORDS_VIEW);
        $this->actingAs($user);
        Queue::fake();

        Livewire::test(ListReplaceKeywords::class)
            ->assertActionHidden(TestAction::make('import')->table())
            ->mountAction(TestAction::make('import')->table())->assertActionNotMounted();
        $this->assertDatabaseCount('replace_keywords', 0);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array<string, string>}> */
    public static function resources(): array
    {
        return [
            'checkout links' => [[
                'model' => CheckoutLink::class, 'resource' => CheckoutLinkResource::class,
                'list' => ListCheckoutLinks::class, 'create' => CreateCheckoutLink::class, 'edit' => EditCheckoutLink::class,
                'view' => Permissions::CHECKOUT_LINKS_VIEW, 'manage' => Permissions::CHECKOUT_LINKS_MANAGE,
                'detail_field' => 'title',
            ]],
            'merchant domains' => [[
                'model' => MerchantDomain::class, 'resource' => MerchantDomainResource::class,
                'list' => ListMerchantDomains::class, 'create' => CreateMerchantDomain::class, 'edit' => EditMerchantDomain::class,
                'view' => Permissions::MERCHANT_DOMAINS_VIEW, 'manage' => Permissions::MERCHANT_DOMAINS_MANAGE,
                'detail_field' => 'host',
            ]],
            'keyword replacements' => [[
                'model' => ReplaceKeyword::class, 'resource' => ReplaceKeywordResource::class,
                'list' => ListReplaceKeywords::class, 'create' => CreateReplaceKeyword::class, 'edit' => EditReplaceKeyword::class,
                'view' => Permissions::REPLACE_KEYWORDS_VIEW, 'manage' => Permissions::REPLACE_KEYWORDS_MANAGE,
                'detail_field' => 'keyword',
            ]],
        ];
    }

    /** @param class-string<Model> $model */
    private function record(string $model, Merchant $merchant): Model
    {
        if ($model === MerchantDomain::class) {
            return MerchantDomain::create(['merchant_id' => $merchant->id, 'host' => 'pay-'.$merchant->id.'.example.com']);
        }
        if ($model === ReplaceKeyword::class) {
            return ReplaceKeyword::create(['merchant_id' => $merchant->id, 'keyword' => 'Example', 'replacement' => 'Sample']);
        }
        $application = Application::createWithCredentials(['merchant_id' => $merchant->id, 'name' => 'Read-only application']);
        $group = PaymentGroup::create(['merchant_id' => $merchant->id, 'group_name' => 'Read-only group', 'group_key' => 'readonly']);

        return CheckoutLink::create([
            'merchant_id' => $merchant->id, 'application_id' => $application->id, 'payment_group_id' => $group->id,
            'title' => 'Customer title', 'link_name' => 'Internal name', 'currency' => 'USD',
            'amount_mode' => CheckoutLink::MODE_FIXED, 'fixed_amount' => '100.00',
        ]);
    }
}

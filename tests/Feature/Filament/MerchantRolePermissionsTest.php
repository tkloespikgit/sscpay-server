<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MerchantRolePermissions;
use App\Filament\Resources\SystemConfigResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Merchant;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\MerchantRoleProvisioningService;
use App\Services\PlatformRoleProvisioningService;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MerchantRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]));
    }

    public function test_save_syncs_existing_and_future_merchants_and_preserves_user_assignments(): void
    {
        $first = $this->merchant('First');
        $second = $this->merchant('Second');
        $second->update(['status' => false]); // 停用商户也必须同步。
        $firstRole = $this->role($first, '订单管理员');
        $firstRole->syncPermissions([Permissions::ORDERS_VIEW, Permissions::ORDERS_SHIP]);
        $user = User::factory()->create(['merchant_id' => $first->id]);
        $user->assignRole($firstRole);
        $user->givePermissionTo(Permissions::TELEGRAM_MANAGE);

        $custom = Role::create(['name' => 'Custom support', 'merchant_id' => $first->id, 'guard_name' => 'web']);
        $custom->givePermissionTo(Permissions::FINANCE_VIEW);
        $user->assignRole($custom);
        $platformRole = app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole();
        $platformPermissions = $platformRole->permissions->pluck('name')->sort()->values()->all();
        $this->role($second, '物流管理员')->delete(); // 同步同时补齐缺失的默认角色。

        // 先加载旧权限，验证保存后读取能获得新的权限集。
        $this->assertFalse($user->can(Permissions::CHECKOUT_LINKS_MANAGE));
        $this->assertTrue($user->can(Permissions::ORDERS_SHIP));
        $data = $this->templateData();
        $data['order_admin'] = [Permissions::ORDERS_VIEW, Permissions::CHECKOUT_LINKS_MANAGE, Permissions::MERCHANT_DOMAINS_MANAGE];
        $data['logistics_admin'] = [];

        Livewire::test(MerchantRolePermissions::class)
            ->assertOk()
            ->fillForm($data)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        foreach ([$first, $second, $this->merchant('Created after save')] as $merchant) {
            $this->assertEqualsCanonicalizing($data['order_admin'], $this->role($merchant, '订单管理员')->permissions->pluck('name')->all());
            $this->assertCount(0, $this->role($merchant, '物流管理员')->permissions);
        }

        $freshUser = $user->fresh();
        $this->assertTrue($freshUser->can(Permissions::CHECKOUT_LINKS_MANAGE));
        $this->assertTrue($freshUser->can(Permissions::MERCHANT_DOMAINS_MANAGE));
        $this->assertFalse($freshUser->can(Permissions::ORDERS_SHIP));
        $this->assertTrue($freshUser->can(Permissions::TELEGRAM_MANAGE));
        $this->assertTrue($freshUser->can(Permissions::FINANCE_VIEW));
        $this->assertEqualsCanonicalizing([$firstRole->id, $custom->id], $freshUser->roles->modelKeys());
        $this->assertSame($platformPermissions, $platformRole->fresh()->permissions->pluck('name')->sort()->values()->all());

        // 重新进入页面仍是保存值；重复同步不会重复建角色。
        Livewire::test(MerchantRolePermissions::class)->assertFormSet($data)->call('save')->assertHasNoFormErrors();
        $this->assertSame(6, Role::where('merchant_id', $second->id)->count());
    }

    public function test_only_enabled_super_admins_in_the_platform_panel_can_access_and_save(): void
    {
        $merchant = $this->merchant('Merchant');
        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->assignRole(app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole());
        $merchantAdmin = User::factory()->create(['merchant_id' => $merchant->id, 'status' => true]);
        $merchantAdmin->assignRole($this->role($merchant, '商户管理员'));
        $disabledSuper = User::factory()->create(['is_super_admin' => true, 'status' => false]);

        foreach ([$manager, $merchantAdmin, $disabledSuper] as $user) {
            $this->actingAs($user);
            $this->assertFalse(MerchantRolePermissions::shouldRegisterNavigation());
            Livewire::test(MerchantRolePermissions::class)->assertForbidden();
        }

        $super = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->actingAs($super);
        Filament::setCurrentPanel('merchant');
        Livewire::test(MerchantRolePermissions::class)->assertForbidden();

        Filament::setCurrentPanel('admin');
        $page = Livewire::test(MerchantRolePermissions::class)->assertOk();
        $super->update(['is_super_admin' => false]);
        $this->actingAs($super->fresh());
        $page->call('save')->assertForbidden();
    }

    public function test_platform_permissions_unknown_roles_and_incomplete_templates_are_rejected_without_writes(): void
    {
        $merchant = $this->merchant('Merchant');
        $initial = $this->role($merchant, '订单管理员')->permissions->pluck('name')->all();
        $data = $this->templateData();
        $invalidPermission = $data;
        $invalidPermission['order_admin'][] = Permissions::SYSTEM_CONFIGS_MANAGE;
        $invalidRole = $data + ['super_admin' => [Permissions::MERCHANTS_MANAGE]];
        $incomplete = $data;
        unset($incomplete['finance_admin']);

        foreach ([$invalidPermission, $invalidRole, $incomplete] as $invalid) {
            try {
                app(MerchantRoleProvisioningService::class)->saveAndSync($invalid);
                $this->fail('Invalid role templates must be rejected.');
            } catch (ValidationException) {
                $this->assertEqualsCanonicalizing($initial, $this->role($merchant, '订单管理员')->permissions->pluck('name')->all());
                $this->assertSame($data, $this->templateData());
            }
        }
    }

    public function test_sync_failure_rolls_back_both_templates_and_permissions(): void
    {
        $merchant = $this->merchant('Merchant');
        $original = $this->templateData();
        $next = $original;
        $next['order_admin'] = [];
        $this->role($merchant, '物流管理员')->delete();
        Role::creating(function (Role $role) {
            if ($role->name === '物流管理员') {
                throw new \RuntimeException('Simulated sync failure');
            }
        });

        try {
            app(MerchantRoleProvisioningService::class)->saveAndSync($next);
            $this->fail('Sync should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated sync failure', $e->getMessage());
        } finally {
            Role::flushEventListeners();
            Role::clearBootedModels();
        }

        $this->assertSame($original, $this->templateData());
        $this->assertEqualsCanonicalizing($original['order_admin'], $this->role($merchant, '订单管理员')->permissions->pluck('name')->all());
    }

    public function test_templates_cannot_be_edited_through_the_generic_system_config_resource(): void
    {
        app(MerchantRoleProvisioningService::class)->saveAndSync($this->templateData());
        $config = SystemConfig::where('config_key', MerchantRoleProvisioningService::TEMPLATE_CONFIG_KEY)->firstOrFail();

        $this->assertFalse(SystemConfigResource::getEloquentQuery()->whereKey($config->id)->exists());
    }

    public function test_user_management_button_is_only_available_to_super_admins(): void
    {
        Livewire::test(ListUsers::class)->assertActionVisible('rolePermissions');

        $manager = User::factory()->create(['merchant_id' => null, 'is_super_admin' => false, 'status' => true]);
        $manager->assignRole(app(PlatformRoleProvisioningService::class)->provisionMerchantManagerRole());
        $this->actingAs($manager);

        Livewire::test(ListUsers::class)->assertActionHidden('rolePermissions');
    }

    public function test_rollout_grants_both_permissions_to_existing_order_admins_but_respects_saved_revocations(): void
    {
        $merchant = $this->merchant('Merchant');
        $role = $this->role($merchant, '订单管理员');
        $role->syncPermissions([Permissions::ORDERS_VIEW, Permissions::TELEGRAM_MANAGE]);

        $this->artisan('permissions:rollout-checkout-links')->assertSuccessful();
        $this->assertTrue($role->fresh()->hasPermissionTo(Permissions::CHECKOUT_LINKS_MANAGE));
        $this->assertTrue($role->fresh()->hasPermissionTo(Permissions::MERCHANT_DOMAINS_MANAGE));
        $this->assertTrue($role->fresh()->hasPermissionTo(Permissions::TELEGRAM_MANAGE));

        $data = $this->templateData();
        $data['order_admin'] = [Permissions::ORDERS_VIEW];
        app(MerchantRoleProvisioningService::class)->saveAndSync($data);
        $this->artisan('permissions:rollout-checkout-links')->assertSuccessful();
        $this->assertFalse($role->fresh()->hasPermissionTo(Permissions::CHECKOUT_LINKS_MANAGE));
        $this->assertFalse($role->fresh()->hasPermissionTo(Permissions::MERCHANT_DOMAINS_MANAGE));
    }

    private function templateData(): array
    {
        return collect(app(MerchantRoleProvisioningService::class)->definitions())
            ->mapWithKeys(fn (array $definition, string $key) => [$key => $definition['permissions']])->all();
    }

    private function merchant(string $name): Merchant
    {
        return Merchant::create([
            'name' => $name,
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => 'merchant@example.com',
        ]);
    }

    private function role(Merchant $merchant, string $label): Role
    {
        return Role::where('merchant_id', $merchant->id)->where('name', $label)->where('guard_name', 'web')->firstOrFail();
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Auth\EditProfile;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AccountSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_account_settings_link_sits_beside_the_avatar_and_not_in_the_sidebar(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
        ]);

        $this->actingAs($user)
            ->followingRedirects()
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder([
                __('admin.account_settings.label'),
                'fi-user-menu-trigger',
                __('admin.account_settings.label'),
            ], false)
            ->assertSee(Filament::getProfileUrl(), false);

        $this->assertFalse(EditProfile::shouldRegisterNavigation());
    }

    public function test_merchant_panel_also_shows_the_account_settings_link_beside_the_avatar(): void
    {
        Filament::setCurrentPanel('merchant');
        $merchant = Merchant::query()->create([
            'name' => 'Test Merchant',
            'contact_person' => 'Tester',
            'contact_phone' => '123456',
            'contact_email' => fake()->unique()->safeEmail(),
            'status' => true,
        ]);
        $user = User::factory()->create([
            'merchant_id' => $merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);

        $this->actingAs($user)
            ->followingRedirects()
            ->get('/merchant')
            ->assertOk()
            ->assertSeeInOrder([
                __('admin.account_settings.label'),
                'fi-user-menu-trigger',
                __('admin.account_settings.label'),
            ], false)
            ->assertSee(Filament::getProfileUrl(), false);
    }

    public function test_settings_page_shows_profile_password_and_two_factor_sections(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
            'name' => 'Aki',
            'email' => User::emailForAccount('aki'),
        ]);

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee(__('admin.account_settings.sections.profile'))
            ->assertSee(__('admin.account_settings.sections.password'))
            ->assertSee(__('admin.account_settings.sections.two_factor'))
            ->assertSee('aki');
    }

    public function test_saving_name_keeps_the_login_account(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
            'name' => 'Old Name',
            'email' => User::emailForAccount('aki'),
        ]);

        Livewire::actingAs($user)
            ->test(EditProfile::class)
            ->fillForm([
                'name' => 'New Name',
                'account' => 'someone-else',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame(User::emailForAccount('aki'), $user->email);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
        ]);

        Livewire::actingAs($user)
            ->test(EditProfile::class)
            ->fillForm([
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'currentPassword' => 'wrong-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_correct_current_password_updates_the_password(): void
    {
        Filament::setCurrentPanel('admin');
        $user = User::factory()->create([
            'merchant_id' => null,
            'is_super_admin' => true,
            'status' => true,
        ]);

        Livewire::actingAs($user)
            ->test(EditProfile::class)
            ->fillForm([
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password-1', $user->refresh()->password));
    }
}

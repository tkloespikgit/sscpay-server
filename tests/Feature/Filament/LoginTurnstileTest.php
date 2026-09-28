<?php

namespace Tests\Feature\Filament;

use App\Filament\Auth\Login as StaffLogin;
use App\Filament\Observer\Auth\Login as ObserverLogin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTurnstileTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_login_pages_render_the_turnstile_widget(): void
    {
        config()->set('services.turnstile.site_key', 'test-site-key');
        config()->set('services.turnstile.secret_key', 'test-secret-key');

        foreach (['admin', 'merchant', 'observer'] as $panel) {
            $this->get('/'.$panel.'/login')
                ->assertOk()
                ->assertSee('data-sitekey="test-site-key"', false)
                ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js');
        }
    }

    public function test_login_requires_a_fresh_verified_token_before_checking_credentials(): void
    {
        config()->set('services.turnstile.site_key', 'test-site-key');
        config()->set('services.turnstile.secret_key', 'test-secret-key');

        foreach (['admin' => StaffLogin::class, 'merchant' => StaffLogin::class, 'observer' => ObserverLogin::class] as $panel => $page) {
            Filament::setCurrentPanel($panel);
            Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

            Livewire::test($page)
                ->set('data.account', 'missing')
                ->set('data.password', 'wrong-password')
                ->call('authenticate')
                ->assertHasErrors(['turnstileToken'])
                ->assertSee(__('admin.auth.turnstile_failed'));

            Http::assertNothingSent();

            Livewire::test($page)
                ->set('data.account', 'missing')
                ->set('data.password', 'wrong-password')
                ->set('turnstileToken', 'invalid-token')
                ->call('authenticate')
                ->assertHasErrors(['turnstileToken']);

            Http::assertSentCount(1);
        }
    }

    public function test_valid_verification_reaches_the_normal_login_check(): void
    {
        config()->set('services.turnstile.site_key', 'test-site-key');
        config()->set('services.turnstile.secret_key', 'test-secret-key');
        Filament::setCurrentPanel('admin');
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        Livewire::test(StaffLogin::class)
            ->set('data.account', 'missing')
            ->set('data.password', 'wrong-password')
            ->set('turnstileToken', 'valid-token')
            ->call('authenticate')
            ->assertHasErrors(['data.account']);

        Http::assertSentCount(1);
    }
}

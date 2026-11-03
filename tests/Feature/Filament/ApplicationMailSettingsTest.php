<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApplicationResource\Pages\EditApplication;
use App\Filament\Resources\PaymentMethodResource\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ApplicationMailSettingsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /** @param array<string, string|int> $credentials */
    #[DataProvider('mailConfigurations')]
    public function test_application_administrator_can_save_the_sending_account(string $driver, array $credentials): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $administrator = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'is_super_admin' => false,
            'status' => true,
        ]);
        $administrator->givePermissionTo(Permissions::APPLICATIONS_MANAGE);

        Livewire::actingAs($administrator)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertOk()
            ->assertActionVisible('mailCredentials')
            ->callAction('mailCredentials', [
                'sender_email' => 'notifications@shop.example.com',
                'sender_name' => 'Application Customer Service',
                'mail_driver' => $driver,
                'mail_credentials' => $credentials,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('applications', [
            'id' => $this->application->id,
            'sender_email' => 'notifications@shop.example.com',
            'sender_name' => 'Application Customer Service',
            'mail_driver' => $driver,
        ]);
        $this->assertSame($credentials, $this->application->fresh()->mail_credentials);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** @param array<string, string> $errors */
    #[DataProvider('incompleteMailConfigurations')]
    public function test_application_mail_settings_reject_missing_sender_and_required_credentials(string $driver, array $errors): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $administrator = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $this->application->update([
            'sender_email' => 'original@shop.example.com',
            'sender_name' => 'Original Sender',
            'mail_driver' => 'postmark',
            'mail_credentials' => ['token' => 'original-application-token'],
        ]);

        Livewire::actingAs($administrator)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->callAction('mailCredentials', [
                'sender_email' => '',
                'sender_name' => 'Incomplete Sender',
                'mail_driver' => $driver,
                'mail_credentials' => [],
            ])
            ->assertHasActionErrors($errors)
            ->assertNotNotified();

        $this->assertDatabaseHas('applications', [
            'id' => $this->application->id,
            'sender_email' => 'original@shop.example.com',
            'sender_name' => 'Original Sender',
            'mail_driver' => 'postmark',
        ]);
        $this->assertSame(['token' => 'original-application-token'], $this->application->fresh()->mail_credentials);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_application_administrator_can_edit_the_payment_link_email_body(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $administrator = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        Livewire::actingAs($administrator)
            ->test(EditApplication::class, ['record' => $this->application->id])
            ->assertOk()
            ->assertFormFieldExists('payment_link_mail_template')
            ->fillForm(['payment_link_mail_template' => '<p>Application payment instructions: {payment_link}</p>'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame('<p>Application payment instructions: {payment_link}</p>', $this->application->fresh()->payment_link_mail_template);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_payment_method_has_neither_sending_account_nor_email_body_configuration(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $administrator = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->makePaymentMethod('mail-template');
        $paymentMethod->forceFill(['payment_link_mail_template' => '<p>Legacy channel template</p>'])->save();

        Livewire::actingAs($administrator)
            ->test(EditPaymentMethod::class, ['record' => $paymentMethod->id])
            ->assertOk()
            ->assertActionDoesNotExist('mailCredentials')
            ->assertFormFieldDoesNotExist('payment_link_mail_template');

        $this->assertSame('<p>Legacy channel template</p>', $paymentMethod->fresh()->payment_link_mail_template);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_duplicating_a_payment_method_discards_legacy_email_body_and_sending_credentials(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->createMerchantAndApplication();
        $administrator = User::factory()->create(['is_super_admin' => true, 'status' => true]);
        $paymentMethod = $this->makePaymentMethod('legacy-mail');
        $paymentMethod->forceFill([
            'sender_email' => 'legacy@channel.example.com',
            'sender_name' => 'Legacy Channel Sender',
            'mail_driver' => 'postmark',
            'mail_credentials' => ['token' => 'legacy-channel-token'],
            'payment_link_mail_template' => '<p>Channel payment template</p>',
        ])->save();

        Livewire::actingAs($administrator)
            ->test(ListPaymentMethods::class)
            ->callAction(TestAction::make('duplicate')->table($paymentMethod))
            ->assertHasNoActionErrors()
            ->assertNotified();

        $copy = PaymentMethod::query()->where('method_code', 'legacy-mail_copy')->sole();
        $this->assertNull($copy->payment_link_mail_template);
        $this->assertNull($copy->sender_email);
        $this->assertNull($copy->sender_name);
        $this->assertNull($copy->mail_driver);
        $this->assertNull($copy->mail_credentials);
        $this->assertSame(['token' => 'legacy-channel-token'], $paymentMethod->fresh()->mail_credentials);
        $this->assertSame('<p>Channel payment template</p>', $paymentMethod->payment_link_mail_template);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, array<string, string|int>}> */
    public static function mailConfigurations(): array
    {
        return [
            'postmark' => ['postmark', ['token' => 'application-postmark-token']],
            'smtp' => ['smtp', [
                'host' => 'smtp.application.example.com',
                'port' => 587,
                'username' => 'application-user',
                'password' => 'application-password',
                'encryption' => 'tls',
            ]],
        ];
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function incompleteMailConfigurations(): array
    {
        return [
            'postmark' => ['postmark', ['sender_email' => 'required', 'mail_credentials.token' => 'required']],
            'smtp' => ['smtp', ['sender_email' => 'required', 'mail_credentials.host' => 'required']],
        ];
    }
}

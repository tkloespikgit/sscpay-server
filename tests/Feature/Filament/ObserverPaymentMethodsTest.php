<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ObserverResource\Pages\CreateObserver;
use App\Filament\Resources\ObserverResource\Pages\EditObserver;
use App\Models\Observer;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class ObserverPaymentMethodsTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_deleted_payment_method_can_be_assigned_and_is_preserved_on_edit(): void
    {
        $this->createMerchantAndApplication();
        $admin = User::create([
            'name' => 'Root', 'email' => 'observer-root@example.com',
            'password' => bcrypt('secret'), 'is_super_admin' => true,
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        $method = $this->makePaymentMethod('archived');
        $method->delete();

        Livewire::test(CreateObserver::class)
            ->assertFormFieldExists('paymentMethods', fn (Select $field) => array_key_exists($method->id, $field->getOptions()))
            ->fillForm(['account' => 'archive-observer', 'password' => 'password123', 'paymentMethods' => [$method->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $observer = Observer::query()->where('email', Observer::emailForAccount('archive-observer'))->sole();
        $this->assertSame([$method->id], $observer->paymentMethods()->pluck('payment_methods.id')->all());

        Livewire::test(EditObserver::class, ['record' => $observer->id])
            ->assertFormSet(['paymentMethods' => [$method->id]])
            ->fillForm(['owner_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('observer_payment_methods', ['observer_id' => $observer->id, 'payment_method_id' => $method->id]);
    }

    public function test_manager_cannot_select_deleted_payment_method_outside_manageable_scope(): void
    {
        $this->createMerchantAndApplication();
        $method = $this->makePaymentMethod('outside');
        $method->delete();
        $manager = User::create([
            'name' => 'Manager', 'email' => 'observer-manager@example.com', 'password' => bcrypt('secret'),
        ]);
        $this->actingAs($manager);
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateObserver::class)
            ->assertFormFieldExists('paymentMethods', fn (Select $field) => ! array_key_exists($method->id, $field->getOptions()))
            ->fillForm(['account' => 'invalid-observer', 'password' => 'password123', 'paymentMethods' => [$method->id]])
            ->call('create')
            ->assertHasFormErrors(['paymentMethods.0']);

        $this->assertDatabaseMissing('observers', ['email' => Observer::emailForAccount('invalid-observer')]);

        $this->merchant->update(['owner_id' => $manager->id]);
        Livewire::test(CreateObserver::class)
            ->assertFormFieldExists('paymentMethods', fn (Select $field) => array_key_exists($method->id, $field->getOptions()))
            ->fillForm(['account' => 'managed-observer', 'password' => 'password123', 'paymentMethods' => [$method->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $observer = Observer::query()->where('email', Observer::emailForAccount('managed-observer'))->sole();
        $this->assertDatabaseHas('observer_payment_methods', ['observer_id' => $observer->id, 'payment_method_id' => $method->id]);
    }
}

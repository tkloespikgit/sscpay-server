<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;
use Throwable;

class PaymentMethodRestoreTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_super_admin_can_filter_deleted_payment_methods_and_restore_one_without_renaming_it(): void
    {
        $this->createMerchantAndApplication();
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $active = $this->makePaymentMethod('still-open');
        $deleted = $this->deletedPaymentMethod('open-code', false);
        $deleted->assignedMerchants()->attach($this->merchant->id);
        $this->actingAs($admin);

        $page = Livewire::test(ListPaymentMethods::class)
            ->assertOk()
            ->assertTableFilterVisible('trashed')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$deleted])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$active, $deleted])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$active])
            ->assertTableActionHidden('edit', $deleted)
            ->assertTableActionHidden('delete', $deleted)
            ->assertTableActionHidden('duplicate', $deleted)
            ->assertTableActionHidden('syncProducts', $deleted)
            ->assertTableActionVisible('restore', $deleted)
            ->callAction(TestAction::make('restore')->table($deleted))
            ->assertNotified(__('filament-actions::restore.single.notifications.restored.title'));

        $restored = PaymentMethod::query()->findOrFail($deleted->id);
        $this->assertSame('open-code', $restored->method_code);
        $this->assertFalse($restored->is_active);
        $this->assertNull($restored->deleted_at);
        $this->assertTrue($restored->assignedMerchants()->whereKey($this->merchant->id)->exists());

        $page->filterTable('trashed', null)
            ->assertCanSeeTableRecords([$active, $restored]);
    }

    public function test_super_admin_restore_appends_recover_when_the_code_is_used_by_a_live_method(): void
    {
        $this->createMerchantAndApplication();
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $deleted = $this->deletedPaymentMethod('taken');
        $deletedRecover = $this->deletedPaymentMethod('taken_recover');
        $occupant = $this->makePaymentMethod('taken');
        $this->actingAs($admin);

        Livewire::test(ListPaymentMethods::class)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($deleted))
            ->assertNotified(Notification::make()
                ->success()
                ->title(__('admin.payment_method.actions.restore_code_recovered_title'))
                ->body(__('admin.payment_method.actions.restore_code_recovered_body', ['code' => 'taken_recover'])));

        $this->assertSame('taken_recover', $deleted->refresh()->method_code);
        $this->assertNull($deleted->deleted_at);
        $this->assertNotNull($deletedRecover->refresh()->deleted_at);
        $this->assertSame('taken', $occupant->refresh()->method_code);

        foreach (['zh_CN', 'en'] as $locale) {
            foreach ([
                'admin.payment_method.actions.restore_code_recovered_title',
                'admin.payment_method.actions.restore_code_recovered_body',
                'admin.payment_method.actions.restore_failed',
                'admin.payment_method.columns.deleted_at',
            ] as $key) {
                $this->assertTrue(Lang::has($key, $locale, false), "Missing {$locale} translation: {$key}");
            }
        }
    }

    public function test_super_admin_restore_increments_the_recover_suffix_when_recover_is_also_taken(): void
    {
        $this->createMerchantAndApplication();
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $deleted = $this->deletedPaymentMethod('taken');
        $this->makePaymentMethod('taken');
        $this->makePaymentMethod('taken_recover');
        $this->actingAs($admin);

        Livewire::test(ListPaymentMethods::class)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($deleted))
            ->assertNotified(__('admin.payment_method.actions.restore_code_recovered_title'));

        $this->assertSame('taken_recover2', $deleted->refresh()->method_code);
        $this->assertNull($deleted->deleted_at);
    }

    public function test_super_admin_restore_keeps_the_recover_suffix_when_the_code_is_already_50_characters(): void
    {
        $this->createMerchantAndApplication();
        $admin = User::factory()->create(['is_super_admin' => true, 'merchant_id' => null, 'status' => true]);
        $code = str_repeat('p', 50);
        $deleted = $this->deletedPaymentMethod($code);
        $this->makePaymentMethod($code);
        $this->actingAs($admin);

        Livewire::test(ListPaymentMethods::class)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($deleted));

        $expected = str_repeat('p', 42).'_recover';
        $this->assertSame(50, mb_strlen($expected));
        $this->assertSame($expected, $deleted->refresh()->method_code);
        $this->assertNull($deleted->deleted_at);
    }

    public function test_merchant_manager_cannot_list_or_restore_deleted_payment_methods(): void
    {
        $this->createMerchantAndApplication();
        $manager = User::factory()->create(['is_super_admin' => false, 'merchant_id' => null, 'status' => true]);
        $manager->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);
        $active = $this->makePaymentMethod('manager-open');
        $deleted = $this->deletedPaymentMethod('manager-deleted');

        $this->assertDeletedPaymentMethodStaysHidden($manager, 'admin', $active, $deleted);
    }

    public function test_merchant_user_cannot_list_or_restore_deleted_payment_methods(): void
    {
        $this->createMerchantAndApplication();
        $user = User::factory()->create([
            'is_super_admin' => false,
            'merchant_id' => $this->merchant->id,
            'status' => true,
        ]);
        $user->givePermissionTo(Permissions::PAYMENT_METHODS_MANAGE);
        $active = $this->makePaymentMethod('merchant-open');
        $deleted = $this->deletedPaymentMethod('merchant-deleted');

        $this->assertDeletedPaymentMethodStaysHidden($user, 'merchant', $active, $deleted);
    }

    private function assertDeletedPaymentMethodStaysHidden(User $user, string $panel, PaymentMethod $active, PaymentMethod $deleted): void
    {
        Filament::setCurrentPanel($panel);
        $this->actingAs($user);

        $page = Livewire::test(ListPaymentMethods::class)
            ->assertOk()
            ->assertTableFilterHidden('trashed')
            ->set('tableFilters', ['trashed' => ['value' => false]])
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$deleted]);

        try {
            $page->callAction(TestAction::make('restore')->table($deleted));
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (Throwable) {
            // 已删除记录不在列表查询里，动作挂不到这条记录上。
        }

        $this->assertNotNull(PaymentMethod::withTrashed()->findOrFail($deleted->id)->deleted_at);
    }

    private function deletedPaymentMethod(string $code, bool $active = true): PaymentMethod
    {
        $method = $this->makePaymentMethod($code);
        $method->update(['is_active' => $active]);
        $method->delete();

        return $method;
    }
}

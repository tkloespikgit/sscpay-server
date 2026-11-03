<?php

namespace Tests\Feature;

use App\Filament\Resources\MerchantResource\Pages\EditMerchant;
use App\Filament\Resources\MerchantResource\RelationManagers\PaymentSettingsRelationManager;
use App\Models\MerchantPaymentSetting;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class MerchantPaymentSettlementOverridesTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_manager_can_edit_one_payment_methods_settlement_terms_without_changing_other_terms(): void
    {
        $this->createMerchantAndApplication();
        $this->actingAs($this->manager());
        $method = $this->makePaymentMethod('individual_settlement');
        $otherMethod = $this->makePaymentMethod('unchanged_settlement');
        $setting = MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $method->id,
            'fee_percent' => '2.1250',
            'settlement_mode' => 'immediate',
            'settlement_days' => 2,
        ]);
        $otherSetting = MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $otherMethod->id,
            'settlement_mode' => 'immediate',
            'settlement_days' => 3,
        ]);
        Http::preventStrayRequests();

        Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $this->merchant, 'pageClass' => EditMerchant::class])
            ->callTableAction('edit', $setting, ['settlement_mode' => 'shipped', 'settlement_days' => 14])
            ->assertHasNoTableActionErrors();

        $this->assertSame('shipped', $setting->fresh()->settlement_mode);
        $this->assertSame(14, $setting->fresh()->settlement_days);
        $this->assertSame('2.1250', $setting->fresh()->fee_percent);
        $this->assertSame('immediate', $otherSetting->fresh()->settlement_mode);
        $this->assertSame(3, $otherSetting->fresh()->settlement_days);
        $this->assertSame('immediate', $this->merchant->fresh()->settlement_mode);
        $this->assertSame(0, $this->merchant->fresh()->settlement_days);
        Http::assertNothingSent();
    }

    public function test_clearing_individual_settlement_terms_restores_the_merchant_defaults(): void
    {
        $this->createMerchantAndApplication();
        $this->actingAs($this->manager());
        $this->merchant->update(['settlement_mode' => 'shipped', 'settlement_days' => 7]);
        $method = $this->makePaymentMethod('clear_settlement');
        $setting = MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $method->id,
            'settlement_mode' => 'immediate',
            'settlement_days' => 0,
        ]);
        Http::preventStrayRequests();

        Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $this->merchant, 'pageClass' => EditMerchant::class])
            ->callTableAction('edit', $setting, ['settlement_mode' => null, 'settlement_days' => null])
            ->assertHasNoTableActionErrors();

        $this->assertNull($setting->fresh()->settlement_mode);
        $this->assertNull($setting->fresh()->settlement_days);
        $effective = $method->configurationForMerchant($this->merchant->fresh());
        $this->assertSame('shipped', $effective['settlement_mode']);
        $this->assertSame(7, $effective['settlement_days']);
        Http::assertNothingSent();
    }

    #[TestWith(['settlement_mode', 'unknown'])]
    #[TestWith(['settlement_days', -1])]
    #[TestWith(['settlement_days', 36501])]
    #[TestWith(['settlement_days', 1.5])]
    public function test_invalid_individual_settlement_terms_are_rejected(string $field, int|float|string $value): void
    {
        $this->createMerchantAndApplication();
        $this->actingAs($this->manager());
        $method = $this->makePaymentMethod('invalid_settlement');
        $setting = MerchantPaymentSetting::create([
            'merchant_id' => $this->merchant->id,
            'payment_method_id' => $method->id,
            'settlement_mode' => 'immediate',
            'settlement_days' => 2,
        ]);
        Http::preventStrayRequests();

        Livewire::test(PaymentSettingsRelationManager::class, ['ownerRecord' => $this->merchant, 'pageClass' => EditMerchant::class])
            ->callTableAction('edit', $setting, [$field => $value])
            ->assertHasTableActionErrors([$field]);

        $this->assertSame('immediate', $setting->fresh()->settlement_mode);
        $this->assertSame(2, $setting->fresh()->settlement_days);
        Http::assertNothingSent();
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['merchant_id' => null, 'status' => true, 'is_super_admin' => false]);
        $manager->givePermissionTo(Permissions::MERCHANTS_MANAGE);
        $this->merchant->update(['owner_id' => $manager->id]);

        return $manager;
    }
}

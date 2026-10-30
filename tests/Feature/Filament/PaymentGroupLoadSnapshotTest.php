<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentGroupResource\Pages\EditPaymentGroup;
use App\Filament\Resources\PaymentGroupResource\RelationManagers\PaymentMethodsRelationManager;
use App\Models\PaymentGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class PaymentGroupLoadSnapshotTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_payment_group_relation_manager_shows_today_load_snapshot_columns(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();

        $group = PaymentGroup::create([
            'merchant_id' => $this->merchant->id,
            'group_key' => 'group_stripe',
            'group_name' => 'Stripe Group',
            'is_active' => true,
        ]);
        $light = $this->makePaymentMethod('stripe_card');
        $heavy = $this->makePaymentMethod('antom');
        $group->paymentMethods()->attach($light->id, ['priority' => 100]);
        $group->paymentMethods()->attach($heavy->id, ['priority' => 100]);
        $this->makeOrder('PAID-HEAVY', 'merchant-heavy', [
            'payment_method_id' => $heavy->id,
            'payment_method' => $heavy->method_code,
            'converted_amount' => '200.00',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $admin = User::factory()->create(['is_super_admin' => true, 'status' => true]);

        Livewire::actingAs($admin)
            ->test(PaymentMethodsRelationManager::class, [
                'ownerRecord' => $group,
                'pageClass' => EditPaymentGroup::class,
            ])
            ->assertCanSeeTableRecords([$light, $heavy])
            ->assertSee(__('admin.payment_group.relation_manager.load_ratio'))
            ->assertSee(__('admin.payment_group.relation_manager.candidate_rank'))
            ->assertSee(__('admin.payment_group.relation_manager.candidate_rank_top3', ['rank' => 1]))
            ->assertSee('0.00')
            ->assertSee('2.00');
    }
}

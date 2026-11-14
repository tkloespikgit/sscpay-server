<?php

namespace Tests\Feature;

use App\Services\PaymentCapacityService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCombinedPayments;
use Tests\TestCase;

class CombinedPaymentMySqlTest extends TestCase
{
    use CreatesCombinedPayments;
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:rollback', ['--step' => 4])->assertSuccessful();
            $this->assertFalse(Schema::hasTable('combined_orders'));
            $this->assertFalse(Schema::hasColumn('orders', 'combined_order_id'));
            $this->assertFalse(Schema::hasColumn('orders', 'webhook_version'));
            $this->assertFalse(Schema::hasColumn('payment_groups', 'is_combine_payment_enabled'));
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function beforeRefreshingDatabase(): void
    {
        $name = (string) config('database.connections.mysql.database');
        if (config('database.default') !== 'mysql' || ! str_starts_with($name, 'sscpay_combined_test_')) {
            $this->markTestSkipped('Requires a disposable sscpay_combined_test_* MySQL database.');
        }
        $actual = DB::selectOne('SELECT DATABASE() AS name')->name;
        if ($actual !== $name) {
            throw new \RuntimeException('The actual test database does not match the isolated database name.');
        }
    }

    public function test_capacity_reads_committed_reservations_after_a_repeatable_read_snapshot_has_started(): void
    {
        $this->setupCombinedPayments();
        $method = $this->combinedMethod('shared', '800.00', ['max_amount_per_day' => '1000.00']);
        $other = $this->makeOrder('OTHER', 'OTHER', [
            'payment_method_id' => $method->id, 'status' => 'pending',
            'converted_amount' => '600.00', 'risk_reservation_status' => null,
        ]);
        config(['database.connections.combined_competitor' => config('database.connections.mysql')]);
        $competitor = DB::connection('combined_competitor');
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertNull(DB::table('orders')->where('id', $other->id)->value('risk_reservation_status'));
            $competitor->table('orders')->where('id', $other->id)->update(['risk_reservation_status' => 'held']);
            $this->assertNull(DB::table('orders')->where('id', $other->id)->value('risk_reservation_status'));
            DB::table('payment_methods')->where('id', $method->id)->lockForUpdate()->first();
            $this->assertFalse(app(PaymentCapacityService::class)->permits($method, '800.00'));
            $this->assertSame('400.00', app(PaymentCapacityService::class)->remaining($method)['day']);
        } finally {
            DB::rollBack();
            DB::purge('combined_competitor');
        }
    }
}

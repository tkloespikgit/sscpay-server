<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Services\OrderEventSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_syncs_and_records_the_last_run_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->expectSync();

        $this->artisan('order-events:sync')->assertSuccessful();

        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'order_event._last_run_at',
            'config_value' => now()->toIso8601String(),
        ]);
    }

    public function test_run_before_the_configured_interval_skips_without_updating_the_last_run_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        SystemConfig::set('order_event.sync_interval', '30');
        $lastRun = now()->subMinutes(20)->toIso8601String();
        SystemConfig::set('order_event._last_run_at', $lastRun);
        $this->expectNoSync();

        $this->artisan('order-events:sync')
            ->expectsOutput('Not due yet, skipping this run.')
            ->assertSuccessful();

        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'order_event._last_run_at',
            'config_value' => $lastRun,
        ]);
    }

    public function test_run_at_the_interval_boundary_syncs_and_updates_the_last_run_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        SystemConfig::set('order_event._last_run_at', now()->subMinutes(10)->toIso8601String());
        $this->expectSync();

        $this->artisan('order-events:sync')->assertSuccessful();

        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'order_event._last_run_at',
            'config_value' => now()->toIso8601String(),
        ]);
    }

    public function test_run_after_the_interval_syncs_and_updates_the_last_run_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        SystemConfig::set('order_event._last_run_at', now()->subMinutes(20)->toIso8601String());
        $this->expectSync();

        $this->artisan('order-events:sync')->assertSuccessful();

        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'order_event._last_run_at',
            'config_value' => now()->toIso8601String(),
        ]);
    }

    public function test_future_last_run_time_does_not_trigger_an_early_sync(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $lastRun = now()->addMinutes(20)->toIso8601String();
        SystemConfig::set('order_event._last_run_at', $lastRun);
        $this->expectNoSync();

        $this->artisan('order-events:sync')
            ->expectsOutput('Not due yet, skipping this run.')
            ->assertSuccessful();

        $this->assertDatabaseHas('system_configs', [
            'config_key' => 'order_event._last_run_at',
            'config_value' => $lastRun,
        ]);
    }

    private function expectSync(): void
    {
        Http::preventStrayRequests();
        $this->mock(OrderEventSyncService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sync')->once()->andReturn(['success' => true]);
        });
    }

    private function expectNoSync(): void
    {
        Http::preventStrayRequests();
        $this->mock(OrderEventSyncService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sync');
        });
    }
}

<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Support\OrderStatusColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrderStatusColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_configuration_uses_the_normal_order_list_colors(): void
    {
        SystemConfig::query()->where('config_key', OrderStatusColor::CONFIG_KEY)->delete();

        foreach ([
            'pending' => 'gray',
            'paid' => 'info',
            'shipped' => 'warning',
            'completed' => 'success',
            'cancelled' => 'danger',
            'failed' => 'danger',
            'expired' => 'danger',
            'disputing' => 'warning',
            'dispute_review' => 'warning',
            'partially_refunded' => 'warning',
            'refunded' => 'danger',
            'chargeback' => 'danger',
        ] as $status => $expectedColor) {
            $this->assertSame($expectedColor, OrderStatusColor::get($status), $status);
        }
    }

    public function test_partial_configuration_overrides_only_the_specified_statuses(): void
    {
        SystemConfig::set(OrderStatusColor::CONFIG_KEY, ['paid' => 'warning', 'refunded' => 'success']);

        $this->assertSame('warning', OrderStatusColor::get('paid'));
        $this->assertSame('success', OrderStatusColor::get('refunded'));
        $this->assertSame('gray', OrderStatusColor::get('pending'));
        $this->assertSame('danger', OrderStatusColor::get('chargeback'));
    }

    #[TestWith(['not json'])]
    #[TestWith(['null'])]
    #[TestWith(['["danger"]'])]
    #[TestWith(['{"paid":"invalid"}'])]
    #[TestWith(['{"paid":["danger"]}'])]
    #[TestWith(['{"paid":null}'])]
    #[TestWith(['{"paid":true}'])]
    public function test_invalid_stored_configuration_falls_back_to_the_default(string $json): void
    {
        SystemConfig::set(OrderStatusColor::CONFIG_KEY, $json);

        $this->assertSame('info', OrderStatusColor::get('paid'));
    }

    public function test_unknown_statuses_use_gray_even_if_present_in_stored_json(): void
    {
        SystemConfig::set(OrderStatusColor::CONFIG_KEY, ['unknown' => 'danger']);

        $this->assertSame('gray', OrderStatusColor::get('unknown'));
    }

    public function test_updating_system_configuration_invalidates_the_cached_color(): void
    {
        $this->assertSame('info', OrderStatusColor::get('paid'));

        SystemConfig::set(OrderStatusColor::CONFIG_KEY, ['paid' => 'success']);

        $this->assertSame('success', OrderStatusColor::get('paid'));
    }

    public function test_migration_initializes_the_json_object_and_preserves_existing_custom_colors(): void
    {
        $config = SystemConfig::query()->where('config_key', OrderStatusColor::CONFIG_KEY)->firstOrFail();
        $colors = json_decode($config->config_value, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('json', $config->value_type);
        $this->assertSame('order', $config->group);
        $this->assertCount(12, $colors);
        $this->assertSame('info', $colors['paid']);

        SystemConfig::set(OrderStatusColor::CONFIG_KEY, ['paid' => 'primary']);
        $migration = require database_path('migrations/2026_10_21_111021_add_order_status_colors_to_system_configs.php');

        $migration->up();
        $migration->down();

        $this->assertSame(['paid' => 'primary'], json_decode($config->refresh()->config_value, true));
        $this->assertSame('primary', OrderStatusColor::get('paid'));
    }
}

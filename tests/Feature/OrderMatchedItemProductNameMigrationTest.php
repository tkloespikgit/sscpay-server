<?php

namespace Tests\Feature;

use App\Models\OrderMatchedItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class OrderMatchedItemProductNameMigrationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    public function test_upgrade_and_safe_rollback_preserve_existing_matched_item_snapshots(): void
    {
        $item = $this->createMatchedItem(str_repeat('饰', 255));
        $attributes = $item->fresh()->getAttributes();
        $migration = $this->migration();

        $migration->down();

        $this->assertSame('varchar', Schema::getColumnType('order_matched_items', 'product_name'));
        $this->assertSame($attributes, $item->fresh()->getAttributes());

        $migration->up();

        $this->assertSame('text', Schema::getColumnType('order_matched_items', 'product_name'));
        $this->assertSame($attributes, $item->fresh()->getAttributes());
    }

    public function test_rollback_refuses_to_truncate_long_names_even_in_soft_deleted_snapshots(): void
    {
        $name = str_repeat('饰', 256);
        $item = $this->createMatchedItem($name);
        $item->delete();

        try {
            $this->migration()->down();
            $this->fail('含长商品名称时不应允许缩短字段。');
        } catch (RuntimeException $exception) {
            $this->assertSame('存在超过 255 字符的匹配商品名称，无法安全回滚 product_name 字段长度。', $exception->getMessage());
        }

        $this->assertSame('text', Schema::getColumnType('order_matched_items', 'product_name'));
        $this->assertSame($name, OrderMatchedItem::withTrashed()->findOrFail($item->id)->product_name);
    }

    private function createMatchedItem(string $name): OrderMatchedItem
    {
        $this->createMerchantAndApplication();

        return $this->makeOrder('SSC-LONG-NAME', 'M-LONG-NAME')->matchedItems()->create([
            'product_name' => $name,
            'unit_price' => '100.00',
            'quantity' => 1,
            'total_price' => '100.00',
            'converted_unit_price' => '100.00',
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_16_153313_expand_product_name_on_order_matched_items_table.php');
    }
}

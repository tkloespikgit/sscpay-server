<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_matched_items', function (Blueprint $table) {
            $table->text('product_name')->comment('商品名称')->change();
        });
    }

    public function down(): void
    {
        $lengthFunction = DB::getDriverName() === 'sqlite' ? 'length' : 'char_length';

        if (DB::table('order_matched_items')->whereRaw("{$lengthFunction}(product_name) > 255")->exists()) {
            throw new RuntimeException('存在超过 255 字符的匹配商品名称，无法安全回滚 product_name 字段长度。');
        }

        Schema::table('order_matched_items', function (Blueprint $table) {
            $table->string('product_name', 255)->comment('商品名称')->change();
        });
    }
};

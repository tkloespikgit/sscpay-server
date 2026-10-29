<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 兼容已跑过带 is_enabled 的建表迁移的本地库；新建库 create 迁移已不含该列。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchant_logistics_tracking_settings')) {
            return;
        }

        if (! Schema::hasColumn('merchant_logistics_tracking_settings', 'is_enabled')) {
            return;
        }

        Schema::table('merchant_logistics_tracking_settings', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('merchant_logistics_tracking_settings')) {
            return;
        }

        if (Schema::hasColumn('merchant_logistics_tracking_settings', 'is_enabled')) {
            return;
        }

        Schema::table('merchant_logistics_tracking_settings', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(false)->comment('是否启用物流跟踪对接');
        });
    }
};

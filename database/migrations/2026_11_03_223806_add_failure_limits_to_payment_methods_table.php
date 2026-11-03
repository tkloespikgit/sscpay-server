<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unsignedInteger('max_failed_times')->nullable()->comment('连续建单失败停用阈值，NULL 沿用系统配置');
            $table->unsignedInteger('consecutive_failed_times')->default(0)->comment('连续建单失败次数');
        });

        DB::table('system_configs')->insertOrIgnore([
            'config_key' => 'payment.max_failed_times',
            'config_value' => '5',
            'value_type' => 'number',
            'group' => 'payment',
            'description' => '支付通道连续建单失败停用阈值（正整数）；渠道未单独设置时沿用此值',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('system_config:payment.max_failed_times');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['max_failed_times', 'consecutive_failed_times']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流轨迹跟踪中性字段（Driver 无关命名）+ 物流异常标记。
 * 本迁移尚未上线，直接使用中性列名，不经 track17_* 过渡。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shippings', function (Blueprint $table) {
            $table->string('tracking_provider', 40)->nullable()->after('synced_at')
                ->comment('轨迹 Provider，如 seventeen_track');
            $table->timestamp('tracking_registered_at')->nullable()->after('tracking_provider')
                ->comment('向 Provider 注册成功时间');
            $table->string('tracking_carrier_code', 64)->nullable()->after('tracking_registered_at')
                ->comment('Provider 承运商码');
            $table->string('tracking_status', 40)->nullable()->after('tracking_carrier_code')
                ->comment('主状态，如 InTransit / Delivered');
            $table->string('tracking_sub_status', 60)->nullable()->after('tracking_status')
                ->comment('子状态');
            $table->timestamp('tracking_last_event_at')->nullable()->after('tracking_sub_status')
                ->comment('最新轨迹事件时间');
            $table->string('tracking_last_event_desc', 500)->nullable()->after('tracking_last_event_at')
                ->comment('最新轨迹事件摘要');
            $table->timestamp('tracking_delivered_at')->nullable()->after('tracking_last_event_desc')
                ->comment('签收时间');
            $table->timestamp('tracking_checked_at')->nullable()->after('tracking_delivered_at')
                ->comment('最近一次有效 Webhook 或主动查成功时间');
            $table->timestamp('tracking_last_polled_at')->nullable()->after('tracking_checked_at')
                ->comment('最近一次异常边沿主动查询时间');
            $table->text('tracking_error')->nullable()->after('tracking_last_polled_at')
                ->comment('最近注册/处理错误摘要');
            $table->boolean('logistics_anomaly')->default(false)->after('tracking_error')
                ->comment('当前是否物流异常');
            $table->string('logistics_anomaly_reason', 40)->nullable()->after('logistics_anomaly')
                ->comment('no_delivery_15d / stale_5d');
            $table->timestamp('logistics_anomaly_at')->nullable()->after('logistics_anomaly_reason')
                ->comment('最近置为异常的时间');

            $table->index('logistics_anomaly');
            $table->index('tracking_registered_at');
            $table->index(['tracking_number', 'tracking_carrier_code'], 'order_shippings_tracking_number_carrier_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_shippings', function (Blueprint $table) {
            $table->dropIndex('order_shippings_tracking_number_carrier_index');
            $table->dropIndex(['logistics_anomaly']);
            $table->dropIndex(['tracking_registered_at']);
            $table->dropColumn([
                'tracking_provider',
                'tracking_registered_at',
                'tracking_carrier_code',
                'tracking_status',
                'tracking_sub_status',
                'tracking_last_event_at',
                'tracking_last_event_desc',
                'tracking_delivered_at',
                'tracking_checked_at',
                'tracking_last_polled_at',
                'tracking_error',
                'logistics_anomaly',
                'logistics_anomaly_reason',
                'logistics_anomaly_at',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shippings', function (Blueprint $table) {
            // 物流信息同步到 WordPress 商城系统插件（POST /sync-tracking）的状态：
            // pending 待同步 / synced 已同步 / failed 同步失败。
            // 每次物流记录被写入（新建/补发/改单）都会重置为 pending，见 OrderShipping::recordShipment()。
            $table->string('sync_status', 20)->default('pending')->after('tracking_url')
                ->comment('物流同步状态：pending 待同步 / synced 已同步 / failed 同步失败');
            $table->text('sync_message')->nullable()->after('sync_status')
                ->comment('最近一次同步结果说明（插件返回的 remote_sync_message 或本地异常信息）');
            $table->timestamp('synced_at')->nullable()->after('sync_message')
                ->comment('最近一次同步成功时间');

            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('order_shippings', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_message', 'synced_at']);
        });
    }
};

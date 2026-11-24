<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_configs')
            ->where('config_key', 'order_event._last_run_at')
            ->update([
                'group' => 'order_event',
                'description' => '订单事件自动同步任务上次执行时间（ISO 8601 格式），由系统自动记录，用于判断是否达到同步间隔。',
            ]);
    }

    /**
     * 回滚时保留已补齐的配置元数据。
     */
    public function down(): void {}
};

<?php

use App\Services\TelegramNotificationRouter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore([
            'config_key' => TelegramNotificationRouter::CONFIG_KEY,
            'config_value' => '{}',
            'value_type' => 'json',
            'group' => 'telegram',
            'description' => 'Telegram 通知接收人（由通知接收人页面维护，空对象沿用代码默认）',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('system_config:'.TelegramNotificationRouter::CONFIG_KEY);
    }

    /**
     * 回滚保留管理员可能已经保存的接收人。
     */
    public function down(): void {}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给 system_configs 加 value_type，后台编辑表单据此渲染不同的输入控件
 * （字符串 / 数值 / JSON / 布尔 / 图片），而不是所有配置项都用一个文本框硬编辑。
 * 只影响后台表单展示，不影响 SystemConfig::get()/getArray()/getBool() 的
 * 读取逻辑——那些方法仍然按调用方约定自行解析 config_value 里的原始字符串。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_configs', function (Blueprint $table) {
            $table->string('value_type', 20)->default('string')->after('config_value')->comment('值类型：string/number/json/boolean/image，决定后台编辑表单渲染哪种控件');
        });

        DB::table('system_configs')->whereIn('config_key', [
            'exchange.surcharge_percent',
            'exchange.surcharge_fixed',
            'order_event.sync_interval',
            'payment_link.expire_days',
            'notify.max_attempts',
            'notify.response_body_max_length',
        ])->update(['value_type' => 'number']);

        DB::table('system_configs')->whereIn('config_key', [
            'exchange.supported_currencies',
            'notify.retry_intervals_seconds',
        ])->update(['value_type' => 'json']);

        DB::table('system_configs')->whereIn('config_key', [
            'order_event.sync_enabled',
            'mfa.force_for_admins',
        ])->update(['value_type' => 'boolean']);
    }

    public function down(): void
    {
        Schema::table('system_configs', function (Blueprint $table) {
            $table->dropColumn('value_type');
        });
    }
};

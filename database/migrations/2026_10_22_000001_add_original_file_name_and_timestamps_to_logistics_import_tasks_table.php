<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流上传记录页需要的三个字段。
 *
 * 1. original_file_name：商户本地的文件名。
 *
 *    原来只有 file_name 一列，迁移注释写的是"原始文件名"，但上传动作存的是
 *    Filament 生成的随机存储名（ListOrders 里 basename($data['file'])）。随机名
 *    在商户看来就是 01M5GRAZNCQ25C6QJ9MBNFBSV5.csv，既认不出自己传了哪个文件，
 *    Telegram 通知里显示的也是这串。这里新增一列存原名，file_name 保持原样继续
 *    作为"保存文件名"（OSS 上的对象名），语义靠本注释和模型常量厘清，不做改名
 *    ——已被通知监听器和历史报表引用，改名收益低于风险。
 *
 *    历史数据这一列是 NULL，展示时回退到 file_name（见
 *    LogisticsImportTask::displayFileName()）。
 *
 * 2. started_at / finished_at：解析的起止时刻。
 *
 *    加这两个字段主要是为了识别"卡死"——worker 挂掉时任务会永远停在 processing，
 *    没有任何提示。耗时本身不在页面展示（业务确认过不需要）。
 *
 * 索引 (merchant_id, created_at) 给列表页的"按商户 + 按时间倒序"用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_import_tasks', function (Blueprint $table) {
            $table->string('original_file_name', 255)->nullable()->after('file_name')
                ->comment('上传文件名（用户本地的原始文件名），历史数据为空');
            $table->timestamp('started_at')->nullable()->after('error_log')
                ->comment('开始解析时间（worker 取到任务时写入）');
            $table->timestamp('finished_at')->nullable()->after('started_at')
                ->comment('解析结束时间（成功或文件级失败都会写）');

            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('logistics_import_tasks', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'created_at']);
            $table->dropColumn(['original_file_name', 'started_at', 'finished_at']);
        });
    }
};

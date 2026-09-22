<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流批量导入新增两个可填写列：shipped_at（发货时间）和 tracking_url（物流追踪链接）。
 * 在此之前这两列虽然出现在导出模板里，但导入端完全不读——商户填了也会被静默丢弃。
 *
 * 和 order_no/logistics_company/tracking_number 一样，这里存的是文件里的**原文**，
 * 不做解析：shipped_at 的格式校验放在同步阶段（syncRecord），解析失败时按现有的
 * 逐行错误机制写进 error_message，而不是在读取阶段整批中断。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_import_task_records', function (Blueprint $table) {
            $table->string('shipped_at', 50)->nullable()->after('tracking_number')
                ->comment('文件里填写的发货时间原文（未解析），留空表示按文件上传时间入库');
            $table->string('tracking_url', 255)->nullable()->after('shipped_at')
                ->comment('文件里填写的物流追踪链接，留空表示不改动订单上已有的链接');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_import_task_records', function (Blueprint $table) {
            $table->dropColumn(['shipped_at', 'tracking_url']);
        });
    }
};

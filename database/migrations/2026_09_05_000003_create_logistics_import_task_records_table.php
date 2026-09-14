<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流批量导入的逐行明细。
 *
 * 原本 logistics_import_tasks 只用一个 error_log JSON 列汇总失败行，成功行不留任何
 * 痕迹，出问题时无法追溯"这一行当时读到的是什么、最后匹配到了哪个订单"。这张表
 * 把文件里的每一行都落库：
 *
 *   1. 读取阶段（LogisticsImportService::storeRows()）：先把 CSV 每行原样写入，
 *      status=pending，raw_data 保存整行原始快照；
 *   2. 同步阶段（LogisticsImportService::syncRows()）：逐行执行原有的物流落库 +
 *      插件同步逻辑，成功置 success，失败置 failed 并把原因写进 error_message；
 *   3. 任务表只保留 total_records / success_count / fail_count 三个汇总数（见
 *      logistics_import_tasks），明细一律查这张表。
 *
 * 不加软删除：这是随任务产生的大表（一次导入可能上万行），任务表本身已有软删除，
 * 物理删除任务时通过 task_id 的 cascadeOnDelete 一并清理即可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_import_task_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('logistics_import_tasks')->cascadeOnDelete()->comment('所属导入任务');
            // 订单是财务记录不会被物理删除（见 orders 表的 restrictOnDelete 约定），
            // 这里仍用 nullOnDelete 兜底，避免万一的历史清理把明细行一起带走。
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete()->comment('匹配到的订单 ID，未匹配到为 NULL');
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户（冗余，便于按商户直接查询明细）');
            $table->unsignedInteger('row_number')->comment('CSV 中的行号（表头算第 1 行）');
            $table->string('order_no', 32)->nullable()->comment('文件里填写的系统订单号');
            $table->string('logistics_company', 50)->nullable()->comment('承运商编码');
            $table->string('tracking_number', 100)->nullable()->comment('物流单号');
            $table->text('remark')->nullable()->comment('备注');
            $table->json('raw_data')->nullable()->comment('该行原始数据快照（列名 => 值），用于事后追溯');
            $table->string('status', 20)->default('pending')->comment('pending/success/failed');
            $table->text('error_message')->nullable()->comment('同步失败原因');
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['merchant_id', 'created_at']);
            $table->index('order_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_import_task_records');
    }
};

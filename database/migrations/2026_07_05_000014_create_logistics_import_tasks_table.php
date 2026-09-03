<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_import_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            // 导入任务属于审计追溯数据，操作人被删除不应导致记录一并消失，故改为 restrict
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete()->comment('操作人 ID');
            $table->string('file_name', 255)->comment('原始文件名');
            $table->string('oss_path', 500)->comment('阿里云 OSS 存储路径');
            $table->string('status', 20)->default('pending')->comment('pending/processing/completed/failed');
            $table->integer('total_records')->default(0)->comment('文件总行数（不含表头）');
            $table->integer('success_count')->default(0)->comment('成功同步行数');
            $table->integer('fail_count')->default(0)->comment('失败行数');
            $table->json('error_log')->nullable()->comment('详细错误记录（如 [{"row":3,"order_no":"XXX","error":"订单不存在"}]）');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_import_tasks');
    }
};

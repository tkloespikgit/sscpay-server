<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            $table->string('group_key', 50)->comment('组唯一标识（商户自定义，如 group_eu）');
            $table->string('group_name', 100)->comment('组名（如 "欧洲标准支付组"）');
            $table->text('description')->nullable()->comment('组描述');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            $table->softDeletes();

            // 软删除安全的唯一约束：用虚拟生成列使 group_key 仅在未删除记录间按商户强制唯一
            $table->string('group_key_uniq', 50)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, group_key, NULL)')
                ->comment('生成列：仅未删除支付组参与 merchant_id+group_key 唯一性校验');
            $table->unique(['merchant_id', 'group_key_uniq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_groups');
    }
};

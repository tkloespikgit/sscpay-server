<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            $table->string('method_code', 50)->comment('支付方式代码（如 paypal、stripe、wechat）');
            $table->string('method_name', 100)->comment('展示名称（如 "PayPal 快捷支付"）');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->integer('sort_order')->default(0)->comment('排序权重（数值越小越靠前）');
            $table->decimal('max_amount_per_transaction', 15, 2)->default(0)->comment('单笔交易金额上限（USD），0 表示不限制');
            $table->decimal('max_amount_per_day', 15, 2)->default(0)->comment('单日交易总金额上限（USD），0 表示不限制');
            $table->integer('max_count_per_day')->default(0)->comment('单日交易笔数上限，0 表示不限制');
            $table->decimal('max_amount_per_month', 15, 2)->default(0)->comment('单月交易总金额上限（USD），0 表示不限制');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'is_active']);

            // 同一商户下 method_code 不可重复，否则风控阈值统计会对应不到唯一配置。
            // 软删除安全：用虚拟生成列使 method_code 仅在未删除记录间按商户强制唯一
            $table->string('method_code_uniq', 50)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, method_code, NULL)')
                ->comment('生成列：仅未删除支付方式参与 merchant_id+method_code 唯一性校验');
            $table->unique(['merchant_id', 'method_code_uniq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};

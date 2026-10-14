<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收款链接的「可选支付组集合」（需求 2）。
 *
 * 决策 Q1 方案 A：保留既有的 payment_group_id（必填外键）作为**默认组**，
 * 新增本字段表示"落地页要展示给客户挑的那几个组"。
 *
 *   payment_group_ids 为空  → 落地页不渲染选择区，直接用 payment_group_id 默认组
 *                            （存量链接零迁移零回归）
 *   payment_group_ids 非空  → 落地页渲染选择区，客户必选其一（决策 Q2）
 *
 * ⚠️ 只选到「组」这一层，绝不把选中的组再往下翻译成具体支付方式——
 * 组内的通道一律交给 PaymentService::resolvePaymentMethod() 的加权算法 + 风控
 * 决定。这是需求里"不影响最终支付方式选择"的落地方式（也规避了指定渠道旁路
 * 会跳过全部风控阈值的老问题）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->json('payment_group_ids')->nullable()->after('payment_group_id')
                ->comment('落地页可选的支付组 ID 集合；为空表示不展示选择区，直接走 payment_group_id 默认组');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->dropColumn('payment_group_ids');
        });
    }
};

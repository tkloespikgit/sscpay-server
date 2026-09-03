<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 冗余 payment_method（method_code 字符串）之外新增真正的外键，方便列表页直接
            // JOIN 出 payment_methods.method_name 展示。历史上 method_code 只在同一商户内
            // 唯一，不能跨商户直接按 code 关联，所以这里落一个明确的 FK 列而不是虚拟关联。
            // nullOnDelete：支付方式一般走软删除，这里仅防御极端情况下的硬删除，
            // 保留订单记录本身（不级联删单）。
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')
                ->constrained('payment_methods')->nullOnDelete()
                ->comment('锁定的支付方式（payment_methods.id），用于展示 method_name');
        });

        // 回填历史订单：按 merchant_id + method_code 匹配（含已软删除的支付方式，
        // 保证历史订单也能展示当时锁定的渠道名称）。
        DB::statement(<<<'SQL'
            UPDATE orders o
            INNER JOIN payment_methods pm
                ON pm.merchant_id = o.merchant_id
                AND pm.method_code = o.payment_method
            SET o.payment_method_id = pm.id
            WHERE o.payment_method_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};

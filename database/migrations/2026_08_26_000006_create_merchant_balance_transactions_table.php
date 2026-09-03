<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户');

            // 记录每一次对商户"总余额"的增减（USD）。冻结/释放不改变总余额，
            // 因此不落这张表（由 merchant_withdrawals 自己的状态流转审计）。
            // 类型：order_paid 收款 / refund 退款本金 / refund_fee 退款手续费 /
            //       chargeback 拒付本金 / chargeback_fee 拒付手续费 /
            //       withdrawal 提现放款 / manual_adjust 人工调整
            $table->string('type', 30)->comment('流水类型');
            $table->decimal('amount', 15, 2)->comment('变动金额（USD，正为增、负为减）');
            $table->decimal('balance_before', 15, 2)->comment('变动前总余额（USD）');
            $table->decimal('balance_after', 15, 2)->comment('变动后总余额（USD）');

            // 关联来源（按类型可能有值），不同类型只会填其中之一
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete()->comment('关联订单（收款/退款/拒付）');
            $table->foreignId('order_refund_id')->nullable()->constrained('order_refunds')->nullOnDelete()->comment('关联退款单');
            $table->foreignId('withdrawal_id')->nullable()->constrained('merchant_withdrawals')->nullOnDelete()->comment('关联提现单');

            // 操作管理员：系统自动入账（收款）为 null；手动操作（退款/拒付/提现/人工调整）为操作人。
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete()->comment('操作管理员');
            $table->string('reason', 500)->nullable()->comment('理由/备注（人工调整必填）');

            // 幂等键：仅自动入账等"必须只发生一次"的场景填值（如 order_paid:{order_id}），
            // 手动多次退款等场景留 NULL（MySQL 唯一索引允许多个 NULL 并存）。
            $table->string('idempotency_key', 100)->nullable()->comment('幂等键（自动入账去重用）');

            $table->timestamps();

            $table->index(['merchant_id', 'type']);
            $table->index(['merchant_id', 'created_at']);
            $table->unique('idempotency_key', 'txn_idempotency_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_balance_transactions');
    }
};

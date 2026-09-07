<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通用人工资金冻结：独立于订单/提现，由商户管理员或超级管理员直接对商户
 * 冻结一笔资金（见 BalanceService::freezeFunds()）。release_at 为空表示
 * 只能人工解冻，否则由 fund-freezes:release-due 定时命令到期自动释放
 * （见 MerchantFundFreeze::scopeDueForAutoRelease()）。
 *
 * 冻结/释放只改 merchants.frozen_balance，不落 merchant_balance_transactions
 * 流水——与提现冻结、争议审核冻结的既有约定一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_fund_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户');

            $table->decimal('amount', 15, 2)->comment('冻结金额（USD）');
            $table->string('status', 20)->default('frozen')->comment('状态：frozen 冻结中 / released 已解冻');
            $table->text('reason')->comment('冻结理由');

            $table->timestamp('release_at')->nullable()->comment('计划自动解冻时间；NULL 表示只能人工解冻');

            $table->foreignId('frozen_by')->constrained('users')->restrictOnDelete()->comment('发起冻结的操作人');
            $table->timestamp('frozen_at')->comment('冻结时间');

            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete()->comment('解冻人；系统自动解冻为 NULL');
            $table->timestamp('released_at')->nullable()->comment('解冻时间');
            $table->string('release_type', 10)->nullable()->comment('解冻方式：manual 人工 / auto 系统到期自动解冻');
            $table->text('release_remark')->nullable()->comment('人工解冻备注');

            $table->timestamps();
            // 无软删除：金融审计记录，同 order_dispute_events/merchant_withdrawals 惯例。

            $table->index(['merchant_id', 'status']);
            // 供到期自动解冻 sweep 使用（fund-freezes:release-due）。
            $table->index(['status', 'release_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_fund_freezes');
    }
};

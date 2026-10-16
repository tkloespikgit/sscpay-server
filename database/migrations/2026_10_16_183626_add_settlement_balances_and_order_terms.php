<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->decimal('pending_settlement_balance', 15, 2)->default(0);
            $table->decimal('pending_withdrawal_balance', 15, 2)->default(0);
            $table->string('settlement_mode', 20)->default('immediate');
            $table->unsignedInteger('settlement_days')->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('settlement_mode', 20)->nullable()->comment('NULL 为旧单，不启用新结算规则');
            $table->unsignedInteger('settlement_days')->nullable();
        });

        Schema::table('merchant_withdrawals', function (Blueprint $table) {
            $table->boolean('uses_pending_balance')->default(false)->comment('旧提现继续使用原冻结余额，不回填历史');
        });

        Schema::table('merchant_fund_freezes', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->decimal('remaining_amount', 15, 2)->nullable()->comment('NULL 时沿用原冻结金额');
            $table->unsignedBigInteger('frozen_by')->nullable()->change();
            $table->dateTime('release_at')->nullable()->comment('计划自动解冻时间；NULL 表示只能人工解冻')->change();
        });

        Schema::table('merchant_balance_transactions', function (Blueprint $table) {
            $table->decimal('pending_settlement_change', 15, 2)->default(0);
            $table->decimal('pending_withdrawal_change', 15, 2)->default(0);
            $table->decimal('frozen_change', 15, 2)->default(0);
            $table->foreignId('fund_freeze_id')->nullable()->constrained('merchant_fund_freezes')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('merchants')->where('pending_settlement_balance', '!=', 0)->orWhere('pending_withdrawal_balance', '!=', 0)->exists()
            || DB::table('merchant_withdrawals')->where('uses_pending_balance', true)->exists()
            || DB::table('merchant_fund_freezes')->whereNotNull('order_id')->exists()
            || DB::table('merchant_fund_freezes')->where('release_at', '>=', '2038-01-19 03:14:07')->exists()
            || DB::table('merchant_balance_transactions')->where(function ($query) {
                $query->where('pending_settlement_change', '!=', 0)
                    ->orWhere('pending_withdrawal_change', '!=', 0)
                    ->orWhere('frozen_change', '!=', 0)
                    ->orWhereNotNull('fund_freeze_id')
                    ->orWhereIn('type', ['order_settlement', 'reserve_freeze', 'reserve_release', 'withdrawal_request', 'withdrawal_rejected', 'dispute_freeze', 'dispute_release']);
            })->exists()
            || DB::table('orders')->whereNotNull('settlement_mode')->exists()) {
            throw new RuntimeException('新结算账务已启用，禁止回滚删除余额或快照；请使用前向迁移。');
        }

        Schema::table('merchant_balance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fund_freeze_id');
            $table->dropColumn(['pending_settlement_change', 'pending_withdrawal_change', 'frozen_change']);
        });
        Schema::table('merchant_fund_freezes', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropUnique(['order_id']);
            $table->dropColumn(['order_id', 'remaining_amount']);
            $table->unsignedBigInteger('frozen_by')->nullable(false)->change();
            $table->timestamp('release_at')->nullable()->comment('计划自动解冻时间；NULL 表示只能人工解冻')->change();
        });
        Schema::table('merchant_withdrawals', fn (Blueprint $table) => $table->dropColumn('uses_pending_balance'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['settlement_mode', 'settlement_days']));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn(['pending_settlement_balance', 'pending_withdrawal_balance', 'settlement_mode', 'settlement_days']));
    }
};

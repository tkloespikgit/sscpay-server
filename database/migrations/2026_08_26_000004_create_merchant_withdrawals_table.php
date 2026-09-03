<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_withdrawals', function (Blueprint $table) {
            $table->id();
            // 提现是财务记录，禁止随商户被物理删除而级联清空
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户');

            $table->decimal('amount', 15, 2)->comment('提现金额（USD）');

            // pending：申请中（金额已冻结）
            // approved：审核通过并放款（冻结转出、余额扣除）
            // rejected：驳回（释放冻结）
            $table->string('status', 20)->default('pending')->comment('状态：pending/approved/rejected');

            $table->string('payout_account', 255)->nullable()->comment('收款账户信息（商户申请时填写）');
            $table->text('remark')->nullable()->comment('商户申请备注');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete()->comment('提现申请人');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->comment('审核/放款管理员');
            $table->timestamp('reviewed_at')->nullable()->comment('审核时间');
            $table->text('review_remark')->nullable()->comment('审核备注/驳回理由');

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_withdrawals');
    }
};

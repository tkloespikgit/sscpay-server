<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户（冗余字段，便于查询隔离）');
            // 金融审计记录：订单不应该因为被物理删除而级联清空冻结/审核历史，故用 restrict。
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete()->comment('关联订单主表');

            // 冗余快照，避免事件列表筛选/搜索每次 join orders。
            $table->string('order_no', 32)->comment('冗余快照：订单号，用于事件列表模糊搜索');
            $table->string('payment_method', 50)->nullable()->comment('冗余快照：订单下单时锁定的支付方式代码，用于按支付渠道筛选');

            $table->string('event_no', 64)->comment('外部案件编号（如银行/收单机构案件号），由财务管理员手动录入');
            $table->string('status', 20)->default('processing')->comment('事件状态：processing 处理中 / closed 已结束');

            $table->text('reason')->comment('争议原因（富文本 HTML，入库前已做 XSS 过滤）');
            $table->json('images')->nullable()->comment('争议原因附带的图片凭证：OSS 路径数组');

            $table->string('final_action', 20)->comment('拟定处理方向：refund 退款 / chargeback 拒付（仅报备用途，本身不触发任何资金动作）');

            $table->unsignedSmallInteger('deadline_value')->comment('处理时限数值（配合 deadline_unit，如 72）');
            $table->string('deadline_unit', 10)->comment('处理时限单位：hours 小时 / days 天');
            $table->unsignedInteger('deadline_hours')->comment('处理时限换算为小时数，用于计算 due_at（如 3 天 = 72）');

            $table->decimal('frozen_amount', 15, 2)->comment('本次冻结金额快照（USD，= 开启时 order.converted_amount；关闭时按此快照释放，不重新读取订单当前金额）');

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete()->comment('发起人（财务管理员或超级管理员），审计记录不因操作人被删除而消失');
            $table->timestamp('opened_at')->comment('发起时间');
            $table->timestamp('due_at')->comment('到期时间 = opened_at + 处理期限；回复不会延长此时间');
            $table->timestamp('reminded_at')->nullable()->comment('到期前 24 小时 Telegram 提醒已发送时间，避免每次调度重复发送');

            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete()->comment('关闭人；系统自动到期关闭时为 NULL');
            $table->timestamp('closed_at')->nullable()->comment('关闭时间');
            $table->string('close_type', 10)->nullable()->comment('关闭方式：manual 人工关闭 / auto 系统到期自动关闭');
            $table->text('close_remark')->nullable()->comment('人工关闭备注');

            $table->timestamps();
            // 无软删除：金融审计记录，已关闭事件需永久可见（同 order_refunds/merchant_withdrawals 惯例）。

            $table->unique(['merchant_id', 'event_no'], 'order_dispute_events_merchant_event_no_unique');
            $table->index(['merchant_id', 'status']);
            $table->index('order_no');
            $table->index('payment_method');
            // 供到期自动关闭 sweep 与到期前提醒扫描共用。
            $table->index(['status', 'due_at']);

            // 数据库层兜底："同一订单同一时间只允许一个处理中的争议审核事件"。
            // 本表没有软删除，所以生成列的判断条件是 status，而不是 order_shippings
            // 迁移里那个基于 deleted_at 的写法。
            $table->unsignedBigInteger('order_id_active_uniq')
                ->nullable()
                ->virtualAs("IF(status = 'processing', order_id, NULL)")
                ->comment('生成列：仅 processing 状态记录参与 order_id 唯一性校验');
            $table->unique('order_id_active_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_dispute_events');
    }
};

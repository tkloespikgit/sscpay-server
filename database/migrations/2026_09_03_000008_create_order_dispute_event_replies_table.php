<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_dispute_event_replies', function (Blueprint $table) {
            $table->id();
            // 紧耦合子记录，随主事件删除级联清理（主事件本身是审计记录、不提供删除入口，
            // 这里的 cascade 只在极端的人工数据库维护场景下才会触发）。
            $table->foreignId('order_dispute_event_id')->constrained('order_dispute_events')->cascadeOnDelete()->comment('关联争议审核事件');
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户（冗余字段，便于查询隔离）');

            $table->text('content')->comment('回复内容（富文本 HTML，入库前已做 XSS 过滤）');
            $table->json('images')->nullable()->comment('回复附带的图片凭证：OSS 路径数组');

            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete()->comment('回复人（商户交易订单管理员），审计记录不因操作人被删除而消失');

            $table->timestamps();
            // 无软删除：append-only 回复线程，本身就是审计记录。

            $table->index(['order_dispute_event_id', 'created_at'], 'order_dispute_event_replies_event_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_dispute_event_replies');
    }
};

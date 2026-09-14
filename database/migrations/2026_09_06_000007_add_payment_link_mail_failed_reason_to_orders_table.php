<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 下单锁定的支付方式与所属 Application 都没有配置可用的邮件发送身份
            // （sender_email + 完整 ESP 凭证）时，SendPaymentLinkJob 不再回退平台
            // 自己的发信账号，而是直接把失败原因落在这里（payment_link_sent_at
            // 保持 NULL），后台订单详情页据此展示"发送失败"而不是"未发送"。
            // 成功发送后会清空为 NULL（重发成功也一样）。
            $table->string('payment_link_mail_failed_reason', 255)->nullable()->after('payment_link_sent_at')->comment('付款链接邮件发送失败原因，成功发送后清空');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_link_mail_failed_reason');
        });
    }
};

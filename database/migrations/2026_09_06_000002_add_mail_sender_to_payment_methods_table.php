<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 支付方式级发件人配置：留空则回退到 applications.sender_email/sender_name
            // （见 PaymentLinkMail::envelope()）。与 Application 的两个同名字段是同一语义，
            // 只是作用域更细，用于同一 Application 下不同支付方式想用不同发件身份的场景。
            $table->string('sender_email', 255)->nullable()->after('fee_fixed')->comment('付款链接邮件发件邮箱，留空回退 Application 配置');
            $table->string('sender_name', 255)->nullable()->after('sender_email')->comment('付款链接邮件发件人名称，留空回退 Application 配置');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['sender_email', 'sender_name']);
        });
    }
};

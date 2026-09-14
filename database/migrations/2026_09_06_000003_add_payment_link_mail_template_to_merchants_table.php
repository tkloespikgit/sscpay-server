<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // 商户自定义付款链接邮件正文，纯文本，支持 {customer_name} / {payment_link}
            // 两个占位变量（见 PaymentLinkMail::content()）。留空则使用系统默认模板
            // （resources/views/emails/payment-link.blade.php），不影响存量商户。
            $table->text('payment_link_mail_template')->nullable()->after('remark')->comment('自定义付款链接邮件正文，留空使用系统默认模板');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('payment_link_mail_template');
        });
    }
};

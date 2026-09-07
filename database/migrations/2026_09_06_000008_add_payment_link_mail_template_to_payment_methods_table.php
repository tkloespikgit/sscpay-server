<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 支付方式自己的付款链接邮件正文，和 applications.payment_link_mail_template
            // 是独立的两份配置，解析优先级与发信身份一致：锁定的支付方式 > 所属
            // Application > 系统默认模板（见 Order::resolveMailTemplate()）。
            // 与发信身份不同的是：即便这一级没有自己的 ESP 凭证（要靠 Application
            // 的账号发信），也可以单独有自己的文案——内容和发信基础设施互不绑定。
            $table->text('payment_link_mail_template')->nullable()->after('mail_credentials')->comment('自定义付款链接邮件正文，留空回退 Application 配置，两处都空用系统默认模板');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('payment_link_mail_template');
        });
    }
};

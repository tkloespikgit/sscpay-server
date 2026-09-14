<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // 商户自带 ESP 账号发付款链接邮件（而不是共用平台账号）：商户自己账号里
            // 发件域名早就验证过，SPF/DKIM 天然对齐，送达率比平台账号统一帮所有商户
            // 发任意域名要好，也不占平台自己的发信配额。mail_driver 目前只支持
            // "postmark"，留字符串字段是为了以后扩展其他 ESP；两者任一为空都视为
            // 未配置，回退平台默认 mailer（见 SendPaymentLinkJob）。
            $table->string('mail_driver', 20)->nullable()->after('payment_link_mail_template')->comment('商户自有 ESP 驱动，目前仅支持 postmark，留空用平台默认');
            $table->text('mail_credentials')->nullable()->after('mail_driver')->comment('商户自有 ESP 凭证（如 Postmark Server Token），加密存储');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['mail_driver', 'mail_credentials']);
        });
    }
};

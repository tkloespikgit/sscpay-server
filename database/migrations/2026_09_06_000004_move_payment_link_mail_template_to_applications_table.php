<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 把付款链接邮件正文模板从商户级移到应用级：同一商户下不同 Application
     * （不同网站/业务线）可能需要用不同的邮件文案，商户级粒度太粗。
     * 迁移当天刚加的 merchants.payment_link_mail_template 还没有任何商户
     * 真正填过内容（功能刚上线），直接删列即可，不需要挪数据。
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('payment_link_mail_template');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->text('payment_link_mail_template')->nullable()->after('sender_name')->comment('自定义付款链接邮件正文，留空使用系统默认模板');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('payment_link_mail_template');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->text('payment_link_mail_template')->nullable()->after('remark')->comment('自定义付款链接邮件正文，留空使用系统默认模板');
        });
    }
};

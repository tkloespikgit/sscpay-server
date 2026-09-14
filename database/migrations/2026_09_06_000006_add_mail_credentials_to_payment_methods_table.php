<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // 支付方式自己的 ESP 驱动/凭证，与 applications.mail_driver/mail_credentials
            // 是各自独立的两份配置，互不共用、互不回退（见 Order::resolveMailSender()）。
            $table->string('mail_driver', 20)->nullable()->after('sender_name')->comment('支付方式自有 ESP 驱动，目前支持 postmark/ses，留空表示未配置');
            $table->text('mail_credentials')->nullable()->after('mail_driver')->comment('支付方式自有 ESP 凭证，加密存储（JSON：postmark 存 token，ses 存 key/secret/region）');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['mail_driver', 'mail_credentials']);
        });
    }
};

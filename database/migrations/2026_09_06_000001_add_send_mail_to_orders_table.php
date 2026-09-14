<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 下单时是否要求发送付款链接邮件（API send_mail=Y / 手工建单固定为 true）。
            // 落库而不是只读请求参数：后台"立即重发"按钮要按这个字段判断是否展示。
            $table->boolean('send_mail')->default(false)->after('payment_link_sent_at')->comment('下单时是否请求发送付款链接邮件');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('send_mail');
        });
    }
};

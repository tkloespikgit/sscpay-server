<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * webhook 签名算法统一为与入站请求鉴权一致的 App-ID/Timestamp/X-Nonce 方案后，
 * 签名相关信息改成 HTTP Header 发送、且在每次尝试（含重试）时现算，不再固定在
 * request_payload 里。补一列快照 Header，保留后台排查问题时的可见性。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_notification_attempts', function (Blueprint $table) {
            $table->json('request_headers')->nullable()->after('request_payload')->comment('本次尝试发送的签名 Header 快照（App-ID/Timestamp/X-Nonce）');
        });
    }

    public function down(): void
    {
        Schema::table('order_notification_attempts', function (Blueprint $table) {
            $table->dropColumn('request_headers');
        });
    }
};

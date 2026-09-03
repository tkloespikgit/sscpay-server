<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // WordPress/WooCommerce 站点对接凭证：域名 + REST API 密钥对。
            // 表单层强制必填，数据库保持可空以兼容历史记录。
            $table->string('domain', 255)->nullable()->after('config')
                ->comment('WordPress 网站域名，格式如 https://example.com');
            $table->string('domain_client_id', 255)->nullable()->after('domain')
                ->comment('WooCommerce REST API Consumer Key');
            $table->string('domain_client_sk', 255)->nullable()->after('domain_client_id')
                ->comment('WooCommerce REST API Consumer Secret');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['domain', 'domain_client_id', 'domain_client_sk']);
        });
    }
};

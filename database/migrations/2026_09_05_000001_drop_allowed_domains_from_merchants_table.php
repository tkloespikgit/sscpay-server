<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 移除商户级「回调域名白名单」功能。
 *
 * 下单时 notify_url / return_url / cancel_url 的域名校验已改为与本次下单所属
 * 应用（applications.website）绑定的域名比对，不再依赖商户维度的白名单，
 * 因此 merchants.allowed_domains 这一列连同其数据一并废弃删除。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('allowed_domains');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->json('allowed_domains')->nullable()->after('contact_email')->comment('回调域名白名单，如 ["hat.com", "api.hat.com"]');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 记录系统级支付方式（merchant_id 为空）的创建人：只有超管和这个创建人本人
 * （超管或商户级管理员账号）能编辑/删除该记录，被分配使用的商户无权修改
 * （见 PaymentMethodResource::canManageRecord()）。商户自建的支付方式不使用此字段。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('users')
                ->nullOnDelete()
                ->comment('系统级支付方式的创建人（超管/商户级管理员），商户自建的支付方式不填');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};

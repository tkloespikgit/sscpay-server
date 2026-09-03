<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 普通商户用户直接在 users 表上挂 merchant_id，不再额外建 merchant_users 关联表。
 * - is_super_admin = true 的平台管理员：merchant_id 保持 NULL。
 * - 商户侧用户：merchant_id 必须指向其所属商户。
 * 这张表建在 merchants 迁移之后执行，用以补上外键。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('merchant_id')
                ->nullable()
                ->after('id')
                ->constrained('merchants')
                ->restrictOnDelete()
                ->comment('所属商户（超级管理员为 NULL）');

            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropColumn('merchant_id');
        });
    }
};

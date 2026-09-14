<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商户级管理员（平台侧账号，users.merchant_id = NULL 且 is_super_admin = false）
 * 名下可以挂多个商户，这里记录"这个商户归哪个商户级管理员管"。
 * NULL 表示商户由平台超管直接管理，不属于任何商户级管理员名下。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete()
                ->comment('所属商户级管理员（NULL 表示由平台超管直接管理）');

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropColumn('owner_id');
        });
    }
};

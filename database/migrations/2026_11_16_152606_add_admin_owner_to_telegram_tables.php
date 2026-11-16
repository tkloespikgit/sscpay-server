<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理员自己的 Telegram 接收目标与商户配置共用 telegram_bots，但归属互斥：
 * merchant_id 与 user_id 恰好一个有值。绑定请求同样可以指向被编辑的管理员。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropUnique(['merchant_id_uniq']);
            $table->dropColumn('merchant_id_uniq');
        });

        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->comment('所属商户；与 user_id 互斥')->change();
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->unsignedBigInteger('merchant_id_uniq')
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, merchant_id, NULL)')
                ->comment('生成列：仅未删除记录参与 merchant_id 唯一性校验（每商户仅一个机器人）');
            $table->unique('merchant_id_uniq');
            $table->foreignId('user_id')->nullable()->after('merchant_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('user_id_uniq')
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, user_id, NULL)')
                ->comment('生成列：每名管理员仅一条未删除的 Telegram 配置');
            $table->unique('user_id_uniq');
        });

        Schema::table('telegram_binding_requests', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropUnique(['merchant_id']);
        });

        Schema::table('telegram_binding_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->change();
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->unique('merchant_id');
            $table->foreignId('target_user_id')->nullable()->after('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->unique('target_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_binding_requests', function (Blueprint $table) {
            $table->dropUnique(['target_user_id']);
            $table->dropConstrainedForeignId('target_user_id');
            $table->dropForeign(['merchant_id']);
            $table->dropUnique(['merchant_id']);
        });

        Schema::table('telegram_binding_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable(false)->change();
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->unique('merchant_id');
        });

        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->dropUnique(['user_id_uniq']);
            $table->dropColumn('user_id_uniq');
            $table->dropConstrainedForeignId('user_id');
            $table->dropForeign(['merchant_id']);
            $table->dropUnique(['merchant_id_uniq']);
            $table->dropColumn('merchant_id_uniq');
        });

        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable(false)->comment('所属商户（每个商户只能绑定一个机器人）')->change();
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->unsignedBigInteger('merchant_id_uniq')
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, merchant_id, NULL)')
                ->comment('生成列：仅未删除记录参与 merchant_id 唯一性校验（每商户仅一个机器人）');
            $table->unique('merchant_id_uniq');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物流信息外部同步 API（外部系统/物流商推送，不对应任何后台登录用户）落地时约定
 * operator_id = 0 代表"系统/API 写入"，前端据此显示为 API。原表 operator_id
 * 是引用 users 表的外键，0 不是合法的 users.id，写入会触发外键校验失败，
 * 因此这里去掉该字段的外键约束，只保留普通整数列。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shippings', function (Blueprint $table) {
            $table->dropForeign(['operator_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_shippings', function (Blueprint $table) {
            $table->foreign('operator_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};

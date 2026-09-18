<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_id 放开为可空：NULL 表示"系统级"支付方式（挂在管理员名下，不归属任何
 * 具体商户），可通过 merchant_payment_methods 中间表分配给多个商户使用
 * （见同批次的 create_merchant_payment_methods_table 迁移）。
 *
 * 原 (merchant_id, method_code_uniq) 唯一索引在 merchant_id 允许为 NULL 后会失效：
 * MySQL 唯一索引里多个 NULL 视为互不相同，多条系统级记录的 method_code 可以随意重复。
 * 用生成列 merchant_id_uniq 把 NULL 归一成 0 再建索引，系统级记录之间的 method_code
 * 唯一性和商户记录一样继续生效。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'method_code_uniq']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->comment('所属商户，NULL 表示系统级（挂在管理员名下，可分配给多个商户使用）')->change();

            $table->unsignedBigInteger('merchant_id_uniq')
                ->nullable()
                ->virtualAs('IFNULL(merchant_id, 0)')
                ->comment('生成列：merchant_id 为空（系统级）时按 0 参与唯一性校验，避免系统级记录间 method_code 重复');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique(['merchant_id_uniq', 'method_code_uniq']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['merchant_id_uniq', 'method_code_uniq']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('merchant_id_uniq');
            $table->unsignedBigInteger('merchant_id')->nullable(false)->comment('所属商户')->change();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique(['merchant_id', 'method_code_uniq']);
        });
    }
};

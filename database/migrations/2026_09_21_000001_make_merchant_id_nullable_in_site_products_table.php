<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 站点商品的 merchant_id 放开为可空：站点商品挂在支付方式（站点配置）名下，
 * 支付方式自 2026_09_18 起允许是系统级（merchant_id 为 NULL、挂在管理员名下、
 * 可分配给多个商户），它同步下来的商品自然也没有单一归属商户。
 * 之前的 NOT NULL 约束会让系统级支付方式一同步商品就报
 * "Column 'merchant_id' cannot be null"。
 *
 * 可见性不再依赖这一列：SiteProduct::booted() 里把全局 Scope 改成跟随所属
 * 支付方式的可见范围（见该处注释）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_products', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
        });

        Schema::table('site_products', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable()->comment('所属商户，NULL 表示归属于系统级支付方式')->change();
        });

        Schema::table('site_products', function (Blueprint $table) {
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_products', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
        });

        Schema::table('site_products', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->nullable(false)->comment('所属商户')->change();
        });

        Schema::table('site_products', function (Blueprint $table) {
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });
    }
};

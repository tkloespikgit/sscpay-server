<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收款链接的「名称」（商户内部标识，需求 F2.2）。
 *
 * 与 title 的分工务必区分清楚，两者都必填但用途完全不同：
 *   - title     = **对客标题**，渲染在落地页顶部（checkout/layout.blade.php 的 <h1>）
 *                 以及浏览器标签页（checkout/show.blade.php 的 @section('title')），客户可见；
 *   - link_name = **内部名称**，仅用于后台列表识别与搜索，绝不展示给客户。
 *
 * 为什么不能复用 title：商户往往把 title 写成客户看得懂的话术
 * （如 "TV box - model 209343 checkout"），而后台运营需要在列表里
 * 用「德国区-TV盒子-测试」这类只有内部人才懂的名字快速定位一堆链接。
 * 两件事强行合并，必然有一边将就。同 PaymentGroup 的 group_key / group_name 一个思路。
 *
 * 历史沿革：该列曾以同名存在（旧库注释为「收款链接名称，商户内部标识用」），
 * 在「清空对齐 HEAD」时被当成孤儿列删除。本次按需求正式补回。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            // 数据库层允许为空：存量链接没有这个值，加 NOT NULL 会让迁移直接失败。
            // 「必填」是业务规则，由后台表单（CheckoutLinkResource::form()）承担——
            // 存量链接再次编辑时会被表单要求补填，这正是需求要的效果。
            $table->string('link_name', 255)
                ->nullable()
                ->after('title')
                ->comment('收款链接名称（商户内部标识用，不展示给客户）');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->dropColumn('link_name');
        });
    }
};

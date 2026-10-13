<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付组 Logo（需求 1）。
 *
 * 落地页让客户「点 Logo 选支付组」，所以 Logo 是支付组的一等属性，不再只是
 * 后台的装饰。尺寸约定 320×120（后台 FileUpload 缩放，由浏览器端 FilePond 完成，
 * 非服务端处理），落地页按固定比例渲染，避免商户传上来各种比例的图把版面顶乱。
 *
 * 为空时**不是**降级成显示组名——组名是内部命名（如「pp支付」），直接给海外
 * 买家看很突兀。回退策略见 CheckoutLink::availablePaymentGroups() 与落地页视图：
 * 优先组 Logo → 回退链接自身 Logo（header 那张）→ 都没有才退回组名兜底。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_groups', function (Blueprint $table) {
            $table->string('logo_path', 500)->nullable()->after('group_name')
                ->comment('支付组 Logo 存储路径（公开盘）；落地页客户点选时展示，空则回退链接自身 Logo');
        });
    }

    public function down(): void
    {
        Schema::table('payment_groups', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};

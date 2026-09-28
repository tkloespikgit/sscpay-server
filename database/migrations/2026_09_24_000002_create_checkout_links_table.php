<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 收款链接（Checkout Link）。商户在后台创建一条可反复使用的收银链接，
 * 客户打开后填写联系方式与收货地址，系统当场建单并跳转到支付网关收银台。
 *
 * ⚠️ 与 orders.payment_link_token 不是同一个东西：
 *   - payment_link_token 挂在订单上，一次性，下单成功后邮件发给客户；
 *   - checkout_links 先于订单存在，一条链接可产生任意多笔订单。
 * 命名上一律用 checkout_link / 收款链接，不要写成 payment_link，避免和
 * PaymentLinkMail、SendPaymentLinkJob 这一套既有代码混淆。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户');

            // 应用只提供下单身份（订单归属、邮件/广告凭证），支付组决定走哪条通道。
            // 两者都直属商户、彼此没有关联，所以表单上是两个独立的选择。
            $table->foreignId('application_id')->constrained()->restrictOnDelete()->comment('下单所用应用');
            $table->foreignId('payment_group_id')->constrained()->restrictOnDelete()->comment('收款走的支付组');
            $table->foreignId('merchant_domain_id')->nullable()->constrained()->nullOnDelete()->comment('落地页所用的商户自有域名，为空则用平台默认域名');

            $table->string('slug', 32)->comment('链接标识，落地页地址 /c/{slug}，必须不可猜测');
            $table->string('title', 255)->comment('收款链接标题，如 "TV box - model 209343 checkout"');
            $table->string('logo_path', 500)->nullable()->comment('商户 Logo 的存储路径（OSS），落地页顶部展示');

            // 币种固定单值：需求明确"币种必须是固定的，不能多选"。
            // 客户不能在落地页上切币种，避免同一条链接产生多币种订单、对账口径混乱。
            $table->string('currency', 3)->comment('收款币种（单值，客户不可选）');

            // fixed：金额写死；range：客户在 [min, max] 区间内自行输入。
            $table->string('amount_mode', 10)->default('fixed')->comment('金额模式：fixed 固定金额 / range 金额区间');
            $table->decimal('fixed_amount', 15, 2)->nullable()->comment('固定金额（amount_mode=fixed 时必填）');
            $table->decimal('min_amount', 15, 2)->nullable()->comment('金额区间下限（amount_mode=range 时必填）');
            $table->decimal('max_amount', 15, 2)->nullable()->comment('金额区间上限（amount_mode=range 时必填）');

            // 配置了商品明细 / 运费 / 税费中任意一项时，amount_mode 强制为 fixed，
            // 且必须满足 Σ(商品) + shipping_fee - discount + tax == fixed_amount
            // （与 Order::isAmountValid() 同一条公式，后台保存时先校验一次，
            // 下单时 OrderCreationService 再算一次，两道都过不了不给建单）。
            $table->decimal('shipping_fee', 15, 2)->default(0)->comment('运费');
            $table->decimal('tax', 15, 2)->default(0)->comment('税费');
            $table->decimal('discount', 15, 2)->default(0)->comment('折扣（正数表示减免）');

            $table->boolean('is_active')->default(true)->comment('启用/停用（停用后落地页显示已失效）');
            $table->unsignedBigInteger('orders_count')->default(0)->comment('累计成功建单数，仅用于后台展示');
            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_id');
            $table->index(['merchant_id', 'is_active']);

            // 软删除安全的唯一约束，同 applications.app_id_uniq 的写法。
            $table->string('slug_uniq', 32)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, slug, NULL)')
                ->comment('生成列：仅未删除链接参与 slug 唯一性校验');
            $table->unique('slug_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_links');
    }
};

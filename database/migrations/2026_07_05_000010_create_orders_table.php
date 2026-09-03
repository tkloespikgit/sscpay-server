<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            // 基础关联
            $table->id();
            // 订单是财务记录，禁止随商户/应用被删除而级联清空：改为 restrict，
            // 有历史订单的商户/应用无法被物理删除（软删除不受影响）
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete()->comment('所属商户');
            $table->foreignId('application_id')->constrained()->restrictOnDelete()->comment('调用来源应用');
            $table->foreignId('payment_group_id')->nullable()->constrained('payment_groups')->nullOnDelete()->comment('支付组 ID');

            // 订单号
            $table->string('order_no', 32)->comment('系统订单号');
            $table->string('merchant_order_no', 64)->comment('商户订单号');

            // 订单来源
            $table->string('source', 20)->default('api')->comment('订单来源：api 或 manual');

            // 付款链接
            $table->string('payment_link_token', 64)->comment('付款链接令牌（用于支付页面访问）');
            $table->timestamp('payment_link_sent_at')->nullable()->comment('付款链接邮件发送时间');

            // 金额原始币种
            $table->string('currency', 3)->comment('下单币种（如 EUR、JPY）');
            $table->decimal('subtotal', 15, 2)->comment('商品小计（原始币种）');
            $table->decimal('shipping_fee', 15, 2)->comment('运费（原始币种）');
            $table->decimal('discount', 15, 2)->comment('折扣（原始币种，正数表示减免）');
            $table->decimal('tax', 15, 2)->comment('税金（原始币种）');
            $table->decimal('amount', 15, 2)->comment('最终应付总额（原始币种）');

            // 金额转化（USD）
            $table->string('converted_currency', 3)->default('USD')->comment('转化币种（固定 USD）');
            $table->decimal('converted_amount', 15, 2)->comment('最终应付总额（换算为 USD）');
            $table->decimal('subtotal_converted', 15, 2)->comment('商品小计（USD，下单时汇率快照换算，历史数据不随系统汇率变动）');
            $table->decimal('shipping_fee_converted', 15, 2)->comment('运费（USD）');
            $table->decimal('discount_converted', 15, 2)->comment('折扣（USD）');
            $table->decimal('tax_converted', 15, 2)->comment('税金（USD）');

            // 汇率与汇损快照
            $table->decimal('exchange_rate', 15, 6)->comment('实际结算汇率（含汇损）');
            $table->decimal('original_exchange_rate', 15, 6)->comment('原始市场汇率（不含汇损）');
            $table->decimal('surcharge_percent', 8, 4)->comment('下单时的汇损百分比（如 1.5 表示 1.5%）');
            $table->string('surcharge_type', 20)->default('percent')->comment('汇损类型：percent / fixed');
            $table->decimal('surcharge_amount', 15, 6)->comment('汇损金额（每单位基准币种）');
            $table->decimal('surcharge_fee', 15, 2)->comment('本次订单总汇损费用（USD）');

            // 客户信息
            $table->string('customer_first_name', 100)->comment('客户名');
            $table->string('customer_last_name', 100)->comment('客户姓');
            $table->string('customer_email', 255)->comment('邮箱');
            $table->string('customer_phone', 30)->comment('手机号');

            // 收货地址
            $table->string('shipping_address_line1', 255)->comment('地址行1（街道/门牌号）');
            $table->string('shipping_address_line2', 255)->nullable()->comment('地址行2（公寓/楼层）');
            $table->string('shipping_city', 100)->comment('城市');
            $table->string('shipping_state', 100)->nullable()->comment('州/省');
            $table->string('shipping_country', 2)->comment('国家代码（ISO 3166-1 alpha-2）');
            $table->string('shipping_zip', 20)->comment('邮政编码');

            // 客户端与支付
            // 风控在下单时就锁定唯一支付方式（不再返回可选列表），因此该字段必填；
            // 若风控过滤后没有任何可用支付方式，则整笔订单创建失败，不会产生 order 记录
            $table->string('payment_method', 50)->comment('系统在下单时锁定的支付方式代码（如 paypal）');
            $table->string('customer_ip', 45)->nullable()->comment('客户端 IP（支持 IPv6）');
            $table->text('user_agent')->nullable()->comment('客户端 User-Agent');
            $table->string('accept_language', 20)->nullable()->comment('客户端 Accept-Language');

            // 回跳地址
            $table->string('notify_url', 500)->nullable()->comment('异步通知地址（服务端回调）');
            $table->string('return_url', 500)->nullable()->comment('支付成功同步跳转地址');
            $table->string('cancel_url', 500)->nullable()->comment('支付失败/取消同步跳转地址');

            // 状态与备注
            $table->string('status', 20)->default('pending')->comment('订单状态：pending/paid/shipped/completed/cancelled/refunded');
            $table->text('remark')->nullable()->comment('商户备注');

            $table->timestamps();
            $table->softDeletes();

            // 软删除安全的唯一约束：MySQL 唯一索引对 NULL 不做去重比较，
            // 因此用虚拟生成列——未删除时等于原字段值，已删除时为 NULL——
            // 只对"未删除"的记录强制唯一，已删除记录之间允许重复，从而支持
            // "软删除后可用同一 merchant_order_no / order_no / payment_link_token 重新下单"
            $table->string('order_no_uniq', 32)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, order_no, NULL)')
                ->comment('生成列：仅未删除订单参与 order_no 唯一性校验');
            $table->string('payment_link_token_uniq', 64)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, payment_link_token, NULL)')
                ->comment('生成列：仅未删除订单参与 payment_link_token 唯一性校验');
            $table->string('merchant_order_no_uniq', 64)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, merchant_order_no, NULL)')
                ->comment('生成列：仅未删除订单参与 merchant_id+merchant_order_no 唯一性校验（幂等键）');

            $table->unique('order_no_uniq');
            $table->unique('payment_link_token_uniq');
            $table->unique(['merchant_id', 'merchant_order_no_uniq']);

            $table->index(['merchant_id', 'status']);
            $table->index('application_id');
            // 仪表盘趋势图 / 按日期筛选订单
            $table->index(['merchant_id', 'created_at']);
            // 支付风控：按支付方式统计当日/当月累计金额时的 DB 兜底查询
            $table->index(['merchant_id', 'payment_method', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

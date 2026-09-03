<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付类型配置模板（超管专属，见 PaymentMethodConfigMapResource）：定义某个
 * 支付网关（如 Stripe/PayPal/Airwallex）需要填哪些配置项。商户创建 PaymentMethod
 * 时选一个模板，表单据此动态渲染出对应的配置输入框，值存进 payment_methods.config。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_config_maps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('支付类型名称，如 Stripe / PayPal / Airwallex');
            $table->boolean('is_active')->default(true)->comment('禁用后新建 PaymentMethod 时不可选，已选中的不受影响');
            $table->json('fields')->comment('配置项定义：[{key,label,required}, ...]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_config_maps');
    }
};

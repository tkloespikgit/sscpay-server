<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付方式的"信息附表"：库存地址、公司/法人资料、供货商资料，跟渠道接入
 * 配置（domain/密钥等）无关，只有超级管理员能在支付方式详情页的弹框里
 * 维护，不出现在普通编辑表单里（见 PaymentMethodProfileAction）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->unique()->constrained()->cascadeOnDelete()
                ->comment('所属支付方式（每个支付方式只有一份信息附表）');
            $table->json('stock_addresses')->nullable()->comment('库存地址，多个，JSON 数组');
            $table->string('company_name', 255)->nullable()->comment('公司名称');
            $table->string('legal_representative_name', 100)->nullable()->comment('法人名字');
            $table->string('legal_representative_phone', 50)->nullable()->comment('法人手机号');
            $table->string('account_email', 255)->nullable()->comment('账户邮箱');
            $table->string('company_address', 500)->nullable()->comment('公司地址');
            $table->string('supplier_name', 255)->nullable()->comment('供货商名称');
            $table->string('supplier_phone', 50)->nullable()->comment('供货商联系电话');
            $table->string('supplier_email', 255)->nullable()->comment('供货商联系邮箱');
            $table->string('supplier_address', 500)->nullable()->comment('供货商地址');
            $table->string('supplier_contact_person', 100)->nullable()->comment('供货商联系人');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_profiles');
    }
};

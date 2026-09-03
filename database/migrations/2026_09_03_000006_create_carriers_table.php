<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('carrier_name', 255)->comment('承运商名称');
            $table->string('carrier_code', 100)->comment('承运商代码，对应 order_shippings.logistics_company');
            $table->string('country_code', 20)->default('GLOBAL')->comment('国家代码');
            $table->string('country_name', 100)->default('Global')->comment('国家名称');
            $table->string('status', 20)->default('enabled')->comment('启用状态：enabled / disabled');
            $table->boolean('pp_supported')->default(true)->comment('是否支持 PayPal，1 支持，0 不支持');
            $table->timestamps();

            $table->unique('carrier_code');
            $table->index('carrier_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};

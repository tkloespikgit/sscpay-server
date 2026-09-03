<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('config_map_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('payment_method_config_maps')
                ->nullOnDelete()
                ->comment('使用哪个支付类型配置模板（payment_method_config_maps），未选则为纯手工配置');

            $table->json('config')
                ->nullable()
                ->after('config_map_id')
                ->comment('config_map_id 对应模板的实际配置值，如 {"secret_key": "sk_xxx"}');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('config_map_id');
            $table->dropColumn('config');
        });
    }
};

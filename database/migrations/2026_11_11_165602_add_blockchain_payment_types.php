<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_method_config_maps', 'payment_methods', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('payment_type', 16)->default('fiat')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'payment_methods', 'payment_method_config_maps'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['payment_type']);
                $table->dropColumn('payment_type');
            });
        }
    }
};

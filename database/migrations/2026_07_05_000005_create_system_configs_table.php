<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->string('config_key', 100)->unique()->comment('配置键名');
            $table->text('config_value')->nullable()->comment('配置值');
            $table->string('group', 50)->nullable()->comment('分组，如 exchange、payment、order_event');
            $table->string('description', 255)->nullable()->comment('配置说明');
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_configs');
    }
};

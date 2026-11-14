<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedTinyInteger('webhook_version')->default(1)->comment('建单时确定的商户通知协议版本');
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('webhook_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->unsignedTinyInteger('webhook_version')->default(1);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('webhook_version');
        });
    }
};

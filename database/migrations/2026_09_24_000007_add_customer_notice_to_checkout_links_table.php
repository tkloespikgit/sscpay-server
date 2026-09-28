<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->text('customer_notice')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->dropColumn('customer_notice');
        });
    }
};

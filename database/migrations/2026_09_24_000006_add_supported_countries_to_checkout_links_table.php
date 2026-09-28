<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->json('supported_countries')->nullable()->after('google_maps_browser_key');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->dropColumn('supported_countries');
        });
    }
};

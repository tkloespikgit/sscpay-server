<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->string('google_maps_browser_key', 255)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_links', function (Blueprint $table) {
            $table->dropColumn('google_maps_browser_key');
        });
    }
};

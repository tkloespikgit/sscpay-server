<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_configs', function (Blueprint $table): void {
            $table->text('secret_value')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('system_configs')->whereNotNull('secret_value')->exists()) {
            throw new RuntimeException('Cannot remove encrypted settings containing credentials. Restore code forward instead.');
        }
        Schema::table('system_configs', function (Blueprint $table): void {
            $table->dropColumn('secret_value');
        });
    }
};

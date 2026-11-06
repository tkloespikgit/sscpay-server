<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * converted_amount 精度改为千分位：加密金额互斥与链上匹配共用该字段。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('converted_amount', 15, 3)
                ->comment('最终应付总额（换算为 USD；千分位，加密单可含金额互斥盐）')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('converted_amount', 15, 2)
                ->comment('最终应付总额（换算为 USD）')
                ->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_groups', function (Blueprint $table) {
            $table->string('timezone', 64)
                ->nullable()
                ->after('description')
                ->comment('决定"当天"统计窗口的时区，空则回退系统时区');
        });

        // priority 语义从"越小越优先"改为"进单占比权重（越大占比越高）"，只改列注释。
        Schema::table('payment_group_methods', function (Blueprint $table) {
            $table->integer('priority')
                ->default(100)
                ->comment('组内进单占比权重（数值越大分配占比越高）')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_group_methods', function (Blueprint $table) {
            $table->integer('priority')
                ->default(100)
                ->comment('组内优先级（数值越小越优先推荐）')
                ->change();
        });

        Schema::table('payment_groups', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};

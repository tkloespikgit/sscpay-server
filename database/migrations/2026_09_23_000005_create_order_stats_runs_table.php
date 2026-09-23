<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每日统计的执行记录，每统计一个 stat_date 写/更新一行。
 *
 * 【为什么需要这张表】order_daily_stats 里"某天没有任何交易"和"那天的统计任务
 * 压根没跑"长得一模一样——都是零行。看板上「上一月」如果中间断了一周，
 * 渲染出来只是个偏小但看起来完全合理的数字，没人会发现。有了这张表，
 * 看板可以对选定周期内缺少执行记录的日期给出明确警告。
 *
 * 同时也是重算的可观测性入口：哪天算的、算出多少行、耗时多久。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_stats_runs', function (Blueprint $table) {
            $table->date('stat_date')->primary()->comment('被统计的日期（系统时区）');
            $table->timestamp('completed_at')->comment('本次统计完成时间');
            $table->unsignedInteger('rows_written')->default(0)->comment('写入的统计行数；0 表示当天确实没有任何交易');
            $table->unsignedInteger('duration_ms')->default(0)->comment('本次统计耗时（毫秒）');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_stats_runs');
    }
};

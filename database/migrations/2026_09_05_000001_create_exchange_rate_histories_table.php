<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 汇率历史快照表。
 *
 * exchange_rates 表上有 (base_currency, target_currency) 唯一约束，
 * ExchangeRate::updateBatchRates() 用 updateOrInsert 原地覆盖，所以那张表
 * 永远只保留"当前最新值"，画不出趋势曲线。这里另建一张追加式（append-only）
 * 快照表：exchange:fetch 每跑一次就按币种各插一行，只增不改。
 *
 * 【为什么不改 exchange_rates】那张表在下单路径上被 getRateWithSurcharge()
 * 直接读取用来冻结订单汇率快照，属于资金链路。给它加历史维度（去掉唯一约束
 * 或加 is_latest 标记）会把风险传导到下单金额计算上，收益不成正比。
 *
 * 【为什么不回填】历史数据无法从第三方补拉（免费版接口不给历史序列），
 * 订单表里的 original_exchange_rate 只是"下单那一刻用过的值"，样本稀疏且
 * 带汇损口径不一致，拿它当汇率历史会画出失真的曲线。所以曲线从本表上线
 * 那一刻起按调度频率累积，初期点位稀疏属预期。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_histories', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3)->default('USD')->comment('基准币种（默认 USD）');
            $table->string('target_currency', 3)->comment('目标币种');
            $table->decimal('rate', 15, 6)->comment('汇率值（1 目标币种 = ? 基准币种），口径与 exchange_rates.rate 一致');
            $table->timestamp('retrieved_at')->comment('该快照的抓取时间');
            $table->timestamps();

            // 趋势查询固定是「基准 + 目标 + 时间范围」，按这个顺序建复合索引，
            // 让 WHERE base=? AND target=? AND retrieved_at>=? 能走索引范围扫描。
            //
            // 索引名必须显式指定：Laravel 默认生成
            // exchange_rate_histories_base_currency_target_currency_retrieved_at_index（72 字符），
            // 超过 MySQL 标识符 64 字符上限，会直接报 1059 让建表失败。
            $table->index(
                ['base_currency', 'target_currency', 'retrieved_at'],
                'exchange_rate_histories_base_target_time_index'
            );

            // 保留期清理是"按时间删旧的"，复合索引的前导列是 base_currency 用不上，
            // 所以单独给 retrieved_at 建一个索引避免全表扫描。
            $table->index('retrieved_at', 'exchange_rate_histories_retrieved_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_histories');
    }
};

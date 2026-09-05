<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 汇率历史快照（append-only）。
 *
 * 与 ExchangeRate 的分工：ExchangeRate 是"当前值"，在下单链路上被
 * getRateWithSurcharge() 读取用于冻结订单汇率快照，(base, target) 唯一、
 * 每次抓取原地覆盖；本模型是"历史序列"，每次抓取追加一批新行，只服务于
 * 后台汇率趋势页，不参与任何资金计算。两张表由 exchange:fetch 在同一次
 * 执行里先后写入，口径（1 目标币种 = ? 基准币种）保持一致。
 */
class ExchangeRateHistory extends Model
{
    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * 默认保留期（天）。exchange:fetch 每小时一次 × 10 个币种 ≈ 240 行/天，
     * 90 天视图是页面上最长的窗口，留 120 天既能覆盖 90 天窗口又有富余，
     * 同时把表体量控制在三万行级别。
     */
    public const DEFAULT_RETENTION_DAYS = 120;

    /**
     * 追加一批快照。用 insert() 而不是逐条 create()：一轮抓取可能有十几个币种，
     * 批量插入只有一次往返；本表没有关联、没有事件监听，不需要模型层的额外开销。
     *
     * @param  array<string, float>  $rates  目标币种 => 汇率
     * @return int 实际写入的行数
     */
    public static function recordBatch(array $rates, string $baseCurrency = 'USD'): int
    {
        if (empty($rates)) {
            return 0;
        }

        $now = now();

        $rows = [];
        foreach ($rates as $targetCurrency => $rate) {
            $rows[] = [
                'base_currency' => $baseCurrency,
                'target_currency' => (string) $targetCurrency,
                'rate' => $rate,
                'retrieved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        static::query()->insert($rows);

        return count($rows);
    }

    /**
     * 最近一次成功抓取的时间。没有任何快照时返回 null（页面据此显示"尚未同步"）。
     */
    public static function lastSyncAt(string $baseCurrency = 'USD'): ?Carbon
    {
        $value = static::query()
            ->where('base_currency', $baseCurrency)
            ->max('retrieved_at');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * 趋势序列：按「日均汇率」聚合，返回连续日期轴 + 每个币种一条数据。
     *
     * 为什么按天聚合而不是直接画原始快照：exchange:fetch 每小时跑一次，
     * 90 天窗口会有两千多个点，折线图挤成一团且毫无信息增量；汇率本身
     * 也是日频才有业务意义的指标。取当天所有快照的 AVG(rate) 作为该日值。
     *
     * 日期轴强制补全为连续的 $days 天：某个币种在某天没有快照（服务停机、
     * 币种是后来才加进 supported_currencies 的）时填 null，让 Chart.js 在该点
     * 断开而不是把前后两天直连成一条虚假的斜线。
     *
     * 基准币种自身（USD/USD）会被排除：exchange.supported_currencies 里为了
     * 让手工建单能选美元，本身就包含 USD，于是 exchange:fetch 也会给 USD 写一行
     * 恒定 1.000000 的快照。这条线没有任何信息量，混在图里只会多一个图例。
     * （只在这一层过滤，不去改 exchange:fetch 的写入行为——exchange_rates 是
     * 下单链路的资金数据源，保持原样零风险；getRateWithSurcharge() 对同币种
     * 本来就短路返回 1.0，根本不会读到那一行。）
     *
     * @return array{labels: string[], series: array<string, array<float|null>>}
     */
    public static function dailySeries(int $days, string $baseCurrency = 'USD'): array
    {
        $days = max(1, $days);
        $today = now()->startOfDay();
        $start = $today->copy()->subDays($days - 1);

        // 日期轴：从 $days-1 天前一直到今天，升序。
        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = $today->copy()->subDays($i)->toDateString();
        }
        $labelIndex = array_flip($labels);

        // GROUP BY 用表达式 DATE(retrieved_at) 而不是 select 里的别名 rate_date：
        // MySQL 虽然允许 GROUP BY 别名，但 ONLY_FULL_GROUP_BY 模式下用别名分组
        // 容易和聚合列的解析顺序打架，写原始表达式最稳。
        $rows = static::query()
            ->where('base_currency', $baseCurrency)
            ->where('target_currency', '!=', $baseCurrency)
            ->where('retrieved_at', '>=', $start)
            ->selectRaw('target_currency, DATE(retrieved_at) as rate_date, AVG(rate) as avg_rate')
            ->groupByRaw('target_currency, DATE(retrieved_at)')
            ->orderBy('target_currency')
            ->get();

        $series = [];
        foreach ($rows as $row) {
            $currency = (string) $row->target_currency;
            $series[$currency] ??= array_fill(0, $days, null);

            // 数据库驱动可能把 DATE() 返回成 "2026-09-05" 或带时间的字符串，
            // 统一过一遍 Carbon 再取 toDateString()，避免索引对不上导致整条线空掉。
            $date = Carbon::parse($row->rate_date)->toDateString();

            if (isset($labelIndex[$date])) {
                $series[$currency][$labelIndex[$date]] = round((float) $row->avg_rate, 6);
            }
        }

        // 币种按字母序输出，保证每次渲染的图例顺序稳定（否则取决于数据库返回顺序）。
        ksort($series);

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * 保留期清理：删除 cutoff 之前的快照，返回删除行数。
     * 分批删除避免一次性 delete 锁住大量行（表虽然不大，但命令是每小时跑的）。
     */
    public static function pruneBefore(Carbon $cutoff, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $affected = static::query()
                ->where('retrieved_at', '<', $cutoff)
                ->limit($chunkSize)
                ->delete();

            $deleted += $affected;
        } while ($affected > 0);

        return $deleted;
    }

    /**
     * 已经存在快照的币种列表（按字母序，含基准币种自身），用于趋势页在还没有
     * 任何数据时也能给出一个合理的币种范围提示。这里不做 dailySeries() 那样的
     * 基准币种过滤——它是"表里到底有什么"的事实陈述，不是画图用的序列。
     *
     * @return Collection<int, string>
     */
    public static function recordedCurrencies(string $baseCurrency = 'USD'): Collection
    {
        return static::query()
            ->where('base_currency', $baseCurrency)
            ->distinct()
            ->orderBy('target_currency')
            ->pluck('target_currency');
    }
}

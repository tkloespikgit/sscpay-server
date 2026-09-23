<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 每日统计的执行记录，见 create_order_stats_runs_table 迁移。
 * 主键是 stat_date（每天一行，重算即覆盖），不是自增 id。
 */
class OrderStatsRun extends Model
{
    protected $primaryKey = 'stat_date';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'stat_date',
        'completed_at',
        'rows_written',
        'duration_ms',
    ];

    /**
     * stat_date 刻意**不**转成 date：Laravel 的 date cast 写库时会序列化成
     * 'Y-m-d 00:00:00'，而查询这边用的是 'Y-m-d'，在 SQLite 上是纯字符串比较，
     * updateOrCreate 会匹配不到自己刚写的那一行，直接撞唯一约束（实测踩过）。
     * 这一列全程当 'Y-m-d' 字符串用，读写两侧口径一致。
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'rows_written' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * 给定日期区间内**没有**执行记录的日期。看板据此提示"这段时间有几天没统计过，
     * 数字可能偏小"——零交易的日子会有一行 rows_written = 0 的记录，
     * 与从没跑过区分得开。
     *
     * @return array<int, string> Y-m-d 列表
     */
    public static function missingDatesBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $ran = static::query()
            ->whereBetween('stat_date', [
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ])
            ->pluck('stat_date')
            // MySQL 的 DATE 列读回来就是 'Y-m-d'，这里再规整一次兜住其它驱动
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();

        $missing = [];
        $cursor = Carbon::parse($from->format('Y-m-d'));
        $end = Carbon::parse($to->format('Y-m-d'));

        while ($cursor->lessThanOrEqualTo($end)) {
            if (! in_array($cursor->toDateString(), $ran, true)) {
                $missing[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $missing;
    }
}

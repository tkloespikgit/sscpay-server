<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantBalanceTransaction;
use App\Models\Order;
use App\Models\Scopes\MerchantScope;
use App\Models\SystemConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** 仅修复历史订单汇损快照，不重放入账、不改余额或不可变资金台账。 */
class OrderSurchargeRecalculationService
{
    private const FIELDS = [
        'currency', 'converted_currency', 'amount', 'subtotal', 'shipping_fee', 'discount', 'tax',
        'exchange_rate', 'original_exchange_rate', 'surcharge_type', 'surcharge_percent',
        'surcharge_amount', 'surcharge_fee', 'converted_amount', 'subtotal_converted',
        'shipping_fee_converted', 'discount_converted', 'tax_converted',
        'fee_percent_amount', 'fee_fixed_amount', 'settlement_amount',
    ];

    public function run(int $merchantId, bool $apply, ?Carbon $from, ?Carbon $to, bool $includeExisting, string $reportPath): array
    {
        $settings = $this->settings();
        $report = [
            'mode' => $apply ? 'applied' : 'preview',
            'merchant_id' => $merchantId,
            'generated_at' => now()->toIso8601String(),
            'settings' => $settings,
            'filters' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'include_existing' => $includeExisting],
            'summary' => array_merge(['changed' => 0, 'unchanged' => 0, 'skipped' => 0], array_fill_keys([
                'no_surcharge_amount', 'new_converted_amount', 'surcharge_difference',
                'old_settlement_amount', 'new_settlement_amount', 'settlement_difference',
            ], '0.00')),
            'orders' => [],
            'stats_dates' => [],
        ];

        if (! is_dir(dirname($reportPath)) && ! mkdir(dirname($reportPath), 0700, true) && ! is_dir(dirname($reportPath))) {
            throw new RuntimeException('无法创建报告目录。');
        }
        // 不覆盖先前的修复报告；报告无法落盘时，不修改订单。
        $file = @fopen($reportPath, 'x');
        if ($file === false) {
            throw new RuntimeException('无法创建报告文件，或报告文件已存在：'.$reportPath);
        }
        @chmod($reportPath, 0600);

        try {
            return DB::transaction(function () use ($merchantId, $apply, $from, $to, $includeExisting, $settings, &$report, $file) {
                $merchant = Merchant::query()->when($apply, fn ($query) => $query->lockForUpdate())->findOrFail($merchantId);
                $report['merchant_name'] = $merchant->name;
                $query = Order::forMerchant($merchantId)
                    ->when($from, fn ($query) => $query->where('created_at', '>=', $from->copy()->startOfDay()))
                    ->when($to, fn ($query) => $query->where('created_at', '<', $to->copy()->addDay()->startOfDay()))
                    ->with(['balanceTransactions' => fn ($query) => $query->forMerchant($merchantId)])
                    ->withExists([
                        'refunds' => fn ($query) => $query->forMerchant($merchantId),
                        'disputeEvents' => fn ($query) => $query->forMerchant($merchantId),
                        // 父订单已明确限定商户；只检测关联记录的商户不一致，不读取其内容。
                        'refunds as mismatched_refunds' => fn ($query) => $query->withoutGlobalScope(MerchantScope::class)->where('merchant_id', '!=', $merchantId),
                        'disputeEvents as mismatched_disputes' => fn ($query) => $query->withoutGlobalScope(MerchantScope::class)->where('merchant_id', '!=', $merchantId),
                        'balanceTransactions as mismatched_transactions' => fn ($query) => $query->withoutGlobalScope(MerchantScope::class)->where('merchant_id', '!=', $merchantId),
                    ])
                    ->when($apply, fn ($query) => $query->lockForUpdate());

                foreach ($query->lazyById(200) as $order) {
                    $row = [
                        'order_id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status,
                        'currency' => $order->currency, 'amount' => $order->amount,
                        'created_at' => $order->created_at?->toIso8601String(),
                        'paid_at' => $order->paid_at?->toIso8601String(),
                        'before' => $order->only(self::FIELDS),
                        'after' => null,
                        'result' => 'skipped', 'reason' => null,
                        'surcharge_difference' => '0.00', 'settlement_difference' => '0.00',
                    ];
                    $reason = $this->skipReason($order, $includeExisting);
                    if ($reason !== null) {
                        $row['reason'] = $reason;
                    } else {
                        try {
                            $after = $this->calculate($order, $settings);
                            $row['after'] = array_replace($row['before'], $after);
                            $changed = collect($after)->contains(fn ($value, $key) => (string) $order->$key !== $value);
                            $row['result'] = $changed ? 'changed' : 'unchanged';
                            if ($changed) {
                                $credit = $order->balanceTransactions->sole();
                                if (bccomp((string) $credit->amount, (string) $order->settlement_amount, 2) !== 0) {
                                    throw new RuntimeException('入账流水与原净入账快照不一致，需单独核对。');
                                }
                                $baseline = bcmul((string) $order->amount, (string) $order->original_exchange_rate, 2);
                                $row['no_surcharge_amount'] = $baseline;
                                $row['surcharge_difference'] = bcsub($after['converted_amount'], $baseline, 2);
                                $row['settlement_difference'] = bcsub($after['settlement_amount'], (string) $order->settlement_amount, 2);
                                $totals = [
                                    'no_surcharge_amount' => $baseline,
                                    'new_converted_amount' => $after['converted_amount'],
                                    'surcharge_difference' => $row['surcharge_difference'],
                                    'old_settlement_amount' => (string) $order->settlement_amount,
                                    'new_settlement_amount' => $after['settlement_amount'],
                                    'settlement_difference' => $row['settlement_difference'],
                                ];
                                foreach ($totals as $key => $value) {
                                    $report['summary'][$key] = bcadd($report['summary'][$key], $value, 2);
                                }
                                $day = ($order->paid_at ?? $order->created_at)->copy()->timezone(config('app.timezone'))->toDateString();
                                $report['stats_dates'][$day] = $day;
                            }
                        } catch (RuntimeException $e) {
                            $row['result'] = 'skipped';
                            $row['reason'] = $e->getMessage();
                        }
                    }
                    if ($apply && $row['result'] === 'changed') {
                        // 写库错误须整批回滚，不能被当成单笔快照异常跳过。
                        // Query Builder 避免触发订单事件、通知或外部请求。只更新此商户此单。
                        Order::forMerchant($merchantId)->whereKey($order->id)->toBase()
                            ->update(array_merge($after, ['updated_at' => now()]));
                    }
                    $report['summary'][$row['result']]++;
                    $report['orders'][] = $row;
                }

                $report['stats_dates'] = array_values($report['stats_dates']);
                sort($report['stats_dates']);
                $this->writeReport($file, $report);

                return $report;
            });
        } catch (Throwable $e) {
            // 整批事务回滚。失败报告不能被当成已执行的调账依据。
            $report['mode'] = 'failed';
            $report['error'] = $e->getMessage();
            try {
                $this->writeReport($file, $report);
            } catch (Throwable) {
                // 保留原始数据库/报告写入错误。
            }
            throw $e;
        } finally {
            fclose($file);
        }
    }

    private function skipReason(Order $order, bool $includeExisting): ?string
    {
        if ($order->currency === 'USD' || $order->converted_currency !== 'USD') {
            return '无需换汇或结算币种不是 USD。';
        }
        if (! in_array($order->status, ['paid', 'shipped', 'completed'], true)) {
            return '仅处理已入账的 paid/shipped/completed 订单。';
        }
        if ($order->mismatched_refunds || $order->mismatched_disputes || $order->mismatched_transactions) {
            return '关联资金或争议记录商户不一致，需单独核对。';
        }
        if ($order->refunds_exists || $order->dispute_events_exists || bccomp((string) $order->refunded_amount, '0', 2) !== 0) {
            return '存在退款或争议记录，需单独核对。';
        }
        if ($order->balanceTransactions->count() !== 1
            || $order->balanceTransactions->first()?->type !== MerchantBalanceTransaction::TYPE_ORDER_PAID
            || $order->balanceTransactions->first()?->idempotency_key !== 'order_paid:'.$order->id) {
            return '缺少唯一自动入账流水，或存在其他资金动作。';
        }
        foreach (['original_exchange_rate', 'fee_percent_amount', 'fee_fixed_amount', 'settlement_amount'] as $field) {
            if ($order->$field === null) {
                return '历史金额快照不完整：'.$field;
            }
        }
        if (bccomp((string) $order->original_exchange_rate, '0', 6) <= 0) {
            return '历史原始汇率必须大于零。';
        }
        $expectedSettlement = bcsub(bcsub((string) $order->converted_amount, (string) $order->fee_percent_amount, 2), (string) $order->fee_fixed_amount, 2);
        if (bccomp($expectedSettlement, (string) $order->settlement_amount, 2) !== 0) {
            return '原净入账快照与手续费金额公式不一致，需单独核对。';
        }
        if (! $includeExisting && (bccomp((string) $order->surcharge_amount, '0', 6) !== 0
            || bccomp((string) $order->surcharge_percent, '0', 4) !== 0
            || bccomp((string) $order->surcharge_fee, '0', 2) !== 0
            || bccomp((string) $order->exchange_rate, (string) $order->original_exchange_rate, 6) !== 0)) {
            return '已存在汇损快照；如需重算，请使用 --include-existing。';
        }

        return null;
    }

    private function calculate(Order $order, array $settings): array
    {
        $original = (string) $order->original_exchange_rate;
        if ($settings['type'] === 'fixed') {
            $rate = bcadd($original, $settings['value'], 6);
            // 与现有下单显示口径一样，百分比快照四舍五入到 4 位。
            $percent = bcdiv(bcmul($settings['value'], '100', 12), $original, 8);
            $percent = bcadd($percent, bccomp($percent, '0', 8) < 0 ? '-0.00005' : '0.00005', 4);
        } else {
            $percent = bcadd($settings['value'], '0', 4);
            $rate = bcmul($original, bcadd('1', bcdiv($percent, '100', 10), 10), 6);
        }
        if (bccomp($rate, '0', 6) <= 0) {
            throw new RuntimeException('最新汇损使实际汇率小于或等于零。');
        }
        $surcharge = bcsub($rate, $original, 6);
        $after = [
            'exchange_rate' => $rate,
            'surcharge_type' => $settings['type'],
            'surcharge_percent' => $percent,
            'surcharge_amount' => $surcharge,
            'surcharge_fee' => bcmul((string) $order->amount, $surcharge, 2),
        ];
        foreach (['amount' => 'converted_amount', 'subtotal' => 'subtotal_converted', 'shipping_fee' => 'shipping_fee_converted',
            'discount' => 'discount_converted', 'tax' => 'tax_converted'] as $source => $target) {
            $after[$target] = bcmul((string) $order->$source, $rate, 2);
        }
        $after['settlement_amount'] = bcsub(bcsub($after['converted_amount'], (string) $order->fee_percent_amount, 2), (string) $order->fee_fixed_amount, 2);
        if (bccomp($after['settlement_amount'], '0', 2) < 0) {
            throw new RuntimeException('最新汇损后的金额不足以覆盖原手续费。');
        }
        foreach ($after as $key => $value) {
            if ($key === 'surcharge_type') {
                continue;
            }
            $maximum = $key === 'surcharge_percent' ? '9999.9999'
                : (in_array($key, ['exchange_rate', 'surcharge_amount'], true) ? '999999999.999999' : '9999999999999.99');
            if (bccomp(ltrim($value, '-'), $maximum, 6) > 0) {
                throw new RuntimeException('重算金额超出字段精度：'.$key);
            }
        }

        return $after;
    }

    private function settings(): array
    {
        // 纠错命令读库中最新配置，避免把一小时前的配置缓存当成最新值。
        $config = SystemConfig::query()->whereIn('config_key', [
            'exchange.surcharge_type', 'exchange.surcharge_percent', 'exchange.surcharge_fixed',
        ])->pluck('config_value', 'config_key');
        $type = $config['exchange.surcharge_type'] ?? 'percent';
        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw new RuntimeException('汇损类型必须是 percent 或 fixed。');
        }
        $value = (string) ($config['exchange.surcharge_'.$type] ?? '0');
        $scale = $type === 'percent' ? 4 : 6;
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)
            || bccomp($value, bcadd($value, '0', $scale), max($scale, strlen($value))) !== 0) {
            throw new RuntimeException('汇损配置必须是十进制数，percent 最多 4 位小数，fixed 最多 6 位小数。');
        }

        return ['type' => $type, 'value' => bcadd($value, '0', $scale)];
    }

    protected function writeReport($file, array $report): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (! rewind($file) || ! ftruncate($file, 0) || fwrite($file, $json) !== strlen($json) || ! fflush($file)) {
            throw new RuntimeException('审计报告写入失败，订单修改已回滚。');
        }
    }
}

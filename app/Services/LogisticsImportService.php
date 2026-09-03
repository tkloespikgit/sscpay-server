<?php

namespace App\Services;

use App\Events\LogisticsImportCompleted;
use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

/**
 * 物流批量导入（4.6 节）：导出模板 → 商户填写 → 上传 OSS → 队列异步处理。
 */
class LogisticsImportService
{
    private const TEMPLATE_HEADERS = ['order_no', 'logistics_company', 'tracking_number', 'remark'];

    /**
     * 生成 CSV 模板内容（未发货的已付款订单，按筛选条件过滤）。
     * 返回原始 CSV 字符串，由调用方（Controller/Filament Action）决定如何下发
     * （直接 download 响应，或先传 OSS 再给下载链接，均可）。
     */
    public function generateTemplate(int $merchantId, array $filters = []): string
    {
        $query = Order::query()
            ->forMerchant($merchantId)
            ->paidUnshipped();

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        $rows = $query->pluck('order_no')->map(fn ($orderNo) => [$orderNo, '', '', '']);

        return $this->toCsv(collect(self::TEMPLATE_HEADERS), $rows);
    }

    /**
     * 处理一个导入任务：从 OSS 读取 CSV，逐行解析，按 order_no 匹配该商户下
     * paid 且未发货的订单，创建/更新物流记录（复用 OrderShipping::recordShipment()，
     * 因此"同一文件里意外出现重复 order_no"也会被当成补发覆盖，而不是报错）。
     *
     * 用 LazyCollection 逐行读取，避免大文件一次性加载到内存（8.3 节）。
     */
    public function processImport(int $taskId): void
    {
        $task = LogisticsImportTask::query()->withoutGlobalScopes()->findOrFail($taskId);

        $task->update(['status' => 'processing']);

        $successCount = 0;
        $errorLog = [];
        $totalRecords = 0;

        try {
            $localPath = $this->downloadToLocalTemp($task->oss_path);

            $rows = LazyCollection::make(function () use ($localPath) {
                $handle = fopen($localPath, 'r');
                $header = fgetcsv($handle); // 跳过表头

                while (($row = fgetcsv($handle)) !== false) {
                    yield array_combine($header, $row);
                }

                fclose($handle);
            });

            $shippingService = app(OrderShippingService::class);

            foreach ($rows as $index => $row) {
                $totalRecords++;
                $rowNumber = $index + 2; // +1 表头 +1 从 1 开始计数

                try {
                    $order = Order::query()
                        ->forMerchant($task->merchant_id)
                        ->paidUnshipped()
                        ->where('order_no', $row['order_no'] ?? '')
                        ->first();

                    if (! $order) {
                        throw new \RuntimeException('订单不存在或不是待发货状态');
                    }

                    $logisticsCompany = $row['logistics_company'] ?? '';

                    if (! Carrier::isValidCode($logisticsCompany)) {
                        throw new \RuntimeException("物流承运商「{$logisticsCompany}」不在系统支持列表中，请联系管理员添加该承运商");
                    }

                    $shippingService->record($order, $task->operator_id, [
                        'logistics_company' => $logisticsCompany,
                        'tracking_number' => $row['tracking_number'] ?? '',
                        'remark' => $row['remark'] ?? null,
                        'shipped_at' => now(),
                    ]);

                    $successCount++;
                } catch (\Throwable $e) {
                    $errorLog[] = [
                        'row' => $rowNumber,
                        'order_no' => $row['order_no'] ?? '',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            @unlink($localPath);

            $task->markCompleted($successCount, count($errorLog), $errorLog);
        } catch (\Throwable $e) {
            $task->markFailed([['row' => 0, 'order_no' => '', 'error' => $e->getMessage()]]);
        }

        $task->update(['total_records' => $totalRecords]);

        event(new LogisticsImportCompleted($task->fresh()));
    }

    private function downloadToLocalTemp(string $ossPath): string
    {
        $contents = Storage::disk('oss')->get($ossPath);
        $localPath = tempnam(sys_get_temp_dir(), 'logistics_import_');
        file_put_contents($localPath, $contents);

        return $localPath;
    }

    private function toCsv(Collection $headers, Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers->all());

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}

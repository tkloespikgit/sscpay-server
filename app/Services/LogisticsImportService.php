<?php

namespace App\Services;

use App\Events\LogisticsImportCompleted;
use App\Models\Carrier;
use App\Models\LogisticsImportTask;
use App\Models\LogisticsImportTaskRecord;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * 物流批量导入（4.6 节）：按订单列表当前筛选条件导出模板 → 商户填写 → 上传 OSS →
 * 队列异步处理 → 逐行明细落 logistics_import_task_records → Telegram 通知商户。
 *
 * 两个方向共用同一套表头（TEMPLATE_HEADERS）：导出的 CSV 商户填完
 * tracking_number / logistics_company / remark 三列后可直接回传，
 * 因此解析端必须认得导出端的全部列名，见 HEADER_ALIASES。
 */
class LogisticsImportService
{
    /**
     * CSV 表头。列顺序即导出顺序，与后台订单列表展示的字段一一对应；
     * 表头用英文字段名（而不是中文标题）是为了让上传解析不依赖界面语言，
     * 商户填写时也只需照列名对号入座。
     */
    public const TEMPLATE_HEADERS = [
        'merchant_id',
        'merchant_name',
        'app_id',
        'website',
        'order_no',
        'merchant_order_no',
        'transaction_id',
        'status',
        'payment_method_name',
        'amount',
        'currency',
        'converted_amount',
        'converted_currency',
        'customer_email',
        'customer_name',
        'tracking_number',
        'logistics_company',
        'tracking_url',
        'shipped_at',
        'paid_at',
        'created_at',
        'remark',
    ];

    /**
     * 上传解析时的表头别名（全部小写比较）：既认当前模板，也认旧版四列模板
     * （order_no/logistics_company/tracking_number/remark），还兜住商户把表头
     * 改成中文的情况。
     */
    private const HEADER_ALIASES = [
        'order_no' => ['order_no', '系统单号', '系统订单号', '订单号'],
        'logistics_company' => ['logistics_company', 'carrier_code', '承运商编码', '承运商代码', '物流公司'],
        'tracking_number' => ['tracking_number', 'tracking_no', '物流单号', '运单号'],
        'remark' => ['remark', '备注'],
    ];

    /** 落库/读取的分批大小，避免上万行的文件一次性吃满内存（8.3 节）。 */
    private const CHUNK_SIZE = 500;

    /**
     * logistics_import_tasks.error_log 里最多保留多少条错误摘要。
     * 完整明细已经逐行落在 logistics_import_task_records 上，这个 JSON 列只用于
     * 后台快速预览，不截断的话一个大文件能把 JSON 列撑到几 MB。
     */
    private const ERROR_LOG_LIMIT = 100;

    // ------------------------------------------------------------------
    // 导出
    // ------------------------------------------------------------------

    /**
     * 按订单列表当前的筛选 + 搜索条件导出全部命中订单（不受分页限制）。
     *
     * @param  int  $merchantId  导出范围锁定的商户 ID。调用方（ListOrders）负责校验
     *                           超级管理员必须先在列表筛选里指定商户；这里再叠一层
     *                           forMerchant()，保证任何情况下都不会跨商户导出。
     * @param  Builder  $query  订单列表页已应用筛选/搜索的查询（ListOrders::getFilteredTableQuery()）
     * @return string 带 UTF-8 BOM 的 CSV 文本（BOM 是为了 Excel 直接双击打开不乱码）
     */
    public function generateTemplate(int $merchantId, Builder $query): string
    {
        $handle = fopen('php://temp/maxmemory:8388608', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('无法创建导出文件缓冲区');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::TEMPLATE_HEADERS);

        // select('orders.*')：表格查询可能带着列的聚合子查询（withCount 等），
        // 导出只需要订单本身的字段，显式收敛 select 也顺便保证 lazyById() 能拿到 id。
        // lazyById 按主键分批取，内存占用与文件行数无关。
        $orders = $query
            ->forMerchant($merchantId)
            ->select('orders.*')
            ->with(['merchant', 'application', 'shipping', 'paymentMethod'])
            ->lazyById(self::CHUNK_SIZE);

        foreach ($orders as $order) {
            fputcsv($handle, array_values($this->buildRow($order)));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * 一行订单 -> TEMPLATE_HEADERS 顺序的取值。日期统一格式化成
     * 'Y-m-d H:i:s'（CSV 里放 Carbon 对象会被序列化成 ISO8601 带时区，商户不好读）。
     */
    private function buildRow(Order $order): array
    {
        $shipping = $order->shipping;

        return [
            'merchant_id' => $order->merchant_id,
            'merchant_name' => $order->merchant?->name,
            'app_id' => $order->application?->app_id,
            'website' => $order->application?->website,
            'order_no' => $order->order_no,
            'merchant_order_no' => $order->merchant_order_no,
            'transaction_id' => $order->transaction_id,
            // 交易状态是给人看的，按后台当前语言输出中文/英文名称
            'status' => __('admin.order.statuses.'.$order->status),
            'payment_method_name' => $order->paymentMethod?->method_name ?? $order->payment_method,
            'amount' => number_format((float) $order->amount, 2, '.', ''),
            'currency' => mb_strtoupper((string) $order->currency),
            'converted_amount' => number_format((float) $order->converted_amount, 2, '.', ''),
            'converted_currency' => mb_strtoupper((string) $order->converted_currency),
            'customer_email' => $order->customer_email,
            'customer_name' => trim($order->customer_first_name.' '.$order->customer_last_name),
            'tracking_number' => $shipping?->tracking_number,
            'logistics_company' => $shipping?->logistics_company,
            'tracking_url' => $shipping?->tracking_url,
            'shipped_at' => $shipping?->shipped_at?->format('Y-m-d H:i:s'),
            'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            'remark' => $shipping?->remark,
        ];
    }

    // ------------------------------------------------------------------
    // 导入
    // ------------------------------------------------------------------

    /**
     * 处理一个导入任务，分两步：
     *
     *   1. storeRows()：把 CSV 每一行原样落到 logistics_import_task_records
     *      （status=pending，raw_data 存整行快照），并回写 total_records；
     *   2. syncRows()：逐行执行原有的物流同步逻辑（OrderShippingService::record()
     *      -> OrderShipping::recordShipment() -> SyncOrderTrackingJob），成功置
     *      success，失败置 failed 并把原因写进 error_message。
     *
     * 最后回写 success_count / fail_count，并 fire LogisticsImportCompleted
     * 让 SendTelegramNotification 通知商户绑定的 Telegram（商户没配 Bot 时
     * TelegramNotificationService::send() 内部会静默跳过）。
     */
    public function processImport(int $taskId): void
    {
        $task = LogisticsImportTask::query()->withoutGlobalScopes()->findOrFail($taskId);

        $task->update(['status' => 'processing']);

        $localPath = null;

        try {
            $localPath = $this->downloadToLocalTemp($task->oss_path);

            $totalRecords = $this->storeRows($task, $localPath);
        } catch (\Throwable $e) {
            // 文件级失败（OSS 下载失败 / 没有表头 / 缺 order_no 列）：一条明细都落不了库，
            // 直接把任务标记为 failed，不再进入逐行同步阶段。
            $task->markFailed([['row' => 0, 'order_no' => '', 'error' => $e->getMessage()]]);

            $this->finish($task);

            return;
        } finally {
            if ($localPath !== null) {
                @unlink($localPath);
            }
        }

        [$successCount, $failCount, $errorLog] = $this->syncRows($task);

        $task->markCompleted($successCount, $failCount, $errorLog);

        $this->finish($task, $totalRecords);
    }

    /**
     * 读取阶段：CSV -> logistics_import_task_records。
     *
     * @return int 实际落库的行数（不含表头、不含空行）
     */
    private function storeRows(LogisticsImportTask $task, string $localPath): int
    {
        // ProcessLogisticsImportJob 配了 3 次重试，任务重跑时先清掉上一次的明细，
        // 否则同一行会出现多条记录、统计数翻倍。
        LogisticsImportTaskRecord::query()
            ->withoutGlobalScopes()
            ->where('task_id', $task->id)
            ->delete();

        $now = now();
        $buffer = [];
        $count = 0;

        foreach ($this->readRows($localPath) as $rowNumber => $row) {
            $buffer[] = [
                'task_id' => $task->id,
                'order_id' => null,
                'merchant_id' => $task->merchant_id,
                'row_number' => $rowNumber,
                // 三个定长列按迁移里的长度截断：完整原文始终在 raw_data 里，
                // 截断只是为了避免 MySQL 严格模式下整批插入报错。
                'order_no' => $this->truncate($row['order_no'], 32),
                'logistics_company' => $this->truncate($row['logistics_company'], 50),
                'tracking_number' => $this->truncate($row['tracking_number'], 100),
                'remark' => $row['remark'] !== '' ? $row['remark'] : null,
                'raw_data' => json_encode($row['raw'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'status' => LogisticsImportTaskRecord::STATUS_PENDING,
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $count++;

            if (count($buffer) >= self::CHUNK_SIZE) {
                LogisticsImportTaskRecord::insert($buffer);
                $buffer = [];

                // 边读边回写总数，任务卡住/超时被 kill 时后台至少能看到已读了多少行
                $task->forceFill(['total_records' => $count])->save();
            }
        }

        if ($buffer !== []) {
            LogisticsImportTaskRecord::insert($buffer);
        }

        $task->forceFill(['total_records' => $count])->save();

        return $count;
    }

    /**
     * 同步阶段：把已落库的 pending 明细逐行喂给原有的物流同步逻辑。
     *
     * chunkById() 会强制按主键升序分批（它会 reorder 掉其他排序），而明细行正是
     * 按 CSV 行号顺序插入的，因此主键顺序 == 文件行号顺序，不需要额外排序。
     * 用 chunkById 而不是 chunk：回调里会改写 status（属于 where 条件），
     * offset 分页会因此漏行，主键游标分页不会。
     *
     * @return array{0: int, 1: int, 2: array<int, array<string, mixed>>} [成功数, 失败数, 错误摘要]
     */
    private function syncRows(LogisticsImportTask $task): array
    {
        $shippingService = app(OrderShippingService::class);

        $successCount = 0;
        $failCount = 0;
        $errorLog = [];

        LogisticsImportTaskRecord::query()
            ->withoutGlobalScopes()
            ->where('task_id', $task->id)
            ->where('status', LogisticsImportTaskRecord::STATUS_PENDING)
            ->chunkById(self::CHUNK_SIZE, function ($records) use ($task, $shippingService, &$successCount, &$failCount, &$errorLog) {
                foreach ($records as $record) {
                    try {
                        $orderId = $this->syncRecord($task, $record, $shippingService);

                        $record->markSuccess($orderId);
                        $successCount++;
                    } catch (\Throwable $e) {
                        $message = $this->errorMessage($e);

                        $record->markFailed($message);
                        $failCount++;

                        if (count($errorLog) < self::ERROR_LOG_LIMIT) {
                            $errorLog[] = [
                                'row' => $record->row_number,
                                'order_no' => (string) $record->order_no,
                                'error' => $message,
                            ];
                        }
                    }
                }
            });

        return [$successCount, $failCount, $errorLog];
    }

    /**
     * 单行同步：完全沿用改造前的匹配与校验规则——只认该商户下 paid 且未发货的订单，
     * 承运商编码必须在 carriers 表里（见 Carrier::isValidCode()），落库走
     * OrderShippingService::record()（内部会投递 SyncOrderTrackingJob 同步给插件）。
     *
     * @return int 命中的订单 ID
     *
     * @throws \RuntimeException 该行无法同步，message 即写进 error_message 的原因
     * @throws ValidationException 订单状态不允许录入物流等业务校验失败
     */
    private function syncRecord(LogisticsImportTask $task, LogisticsImportTaskRecord $record, OrderShippingService $shippingService): int
    {
        $orderNo = trim((string) $record->order_no);

        if ($orderNo === '') {
            throw new \RuntimeException('该行未填写系统订单号（order_no）');
        }

        $order = Order::query()
            ->forMerchant($task->merchant_id)
            ->paidUnshipped()
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            throw new \RuntimeException('订单不存在或不是待发货状态');
        }

        $logisticsCompany = trim((string) $record->logistics_company);
        $trackingNumber = trim((string) $record->tracking_number);

        if ($logisticsCompany === '' || $trackingNumber === '') {
            throw new \RuntimeException('未填写承运商编码（logistics_company）或物流单号（tracking_number）');
        }

        if (! Carrier::isValidCode($logisticsCompany)) {
            throw new \RuntimeException("物流承运商「{$logisticsCompany}」不在系统支持列表中，请联系管理员添加该承运商");
        }

        $shippingService->record($order, $task->operator_id, [
            'logistics_company' => $logisticsCompany,
            'tracking_number' => $trackingNumber,
            'remark' => $record->remark,
            'shipped_at' => now(),
        ]);

        return $order->id;
    }

    /**
     * 统一收尾：回写总行数并 fire 事件通知商户 Telegram。
     * 无论成功、部分失败还是文件级失败都必须走到这里，否则商户永远等不到通知。
     */
    private function finish(LogisticsImportTask $task, ?int $totalRecords = null): void
    {
        if ($totalRecords !== null) {
            $task->update(['total_records' => $totalRecords]);
        }

        event(new LogisticsImportCompleted($task->fresh()));
    }

    // ------------------------------------------------------------------
    // CSV 读取
    // ------------------------------------------------------------------

    /**
     * 逐行读取 CSV，产出 [行号 => ['raw' => 原始列, 'order_no' => ..., ...]]。
     * 用生成器而不是一次性 file_get_contents + parse，保证大文件不吃内存。
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws \RuntimeException 文件打不开、没有表头、或表头里找不到 order_no 列
     */
    private function readRows(string $localPath): \Generator
    {
        $handle = fopen($localPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('无法读取上传的文件');
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === null) {
                throw new \RuntimeException('文件内容为空或缺少表头行');
            }

            $map = $this->mapHeader($header);

            if (! isset($map['order_no'])) {
                throw new \RuntimeException('模板缺少 order_no 列，请先用「导出物流模板」下载最新模板，填好后原样上传');
            }

            $rowNumber = 1; // 表头占第 1 行

            while (($cells = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Excel 另存 CSV 常在末尾留若干空行，整行都是空白时直接跳过，不计入总数
                if ($this->isBlankRow($cells)) {
                    continue;
                }

                yield $rowNumber => $this->cellValues($map, $header, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 表头 -> 规范字段名的列下标映射。第一列可能带 UTF-8 BOM（导出时写入的，
     * Excel 保存也会保留），不剥掉的话 order_no 排在首列时会匹配不上。
     */
    private function mapHeader(array $header): array
    {
        $normalized = [];

        foreach ($header as $index => $name) {
            $normalized[$index] = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $name)));
        }

        $map = [];

        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            $aliases = array_map(fn (string $alias): string => mb_strtolower($alias), $aliases);

            foreach ($normalized as $index => $name) {
                if ($name !== '' && in_array($name, $aliases, true)) {
                    $map[$canonical] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * 按表头映射把一行单元格拆成规范字段，raw 保留「表头名 => 值」的完整快照。
     */
    private function cellValues(array $map, array $header, array $cells): array
    {
        $raw = [];

        foreach ($header as $index => $name) {
            $name = trim(str_replace("\xEF\xBB\xBF", '', (string) $name));
            $raw[$name === '' ? "column_{$index}" : $name] = trim((string) ($cells[$index] ?? ''));
        }

        $pick = fn (string $key): string => trim((string) ($cells[$map[$key] ?? PHP_INT_MAX] ?? ''));

        return [
            'raw' => $raw,
            'order_no' => isset($map['order_no']) ? $pick('order_no') : '',
            'logistics_company' => isset($map['logistics_company']) ? $pick('logistics_company') : '',
            'tracking_number' => isset($map['tracking_number']) ? $pick('tracking_number') : '',
            'remark' => isset($map['remark']) ? $pick('remark') : '',
        ];
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function downloadToLocalTemp(string $ossPath): string
    {
        $contents = Storage::disk('oss')->get($ossPath);
        $localPath = tempnam(sys_get_temp_dir(), 'logistics_import_');

        if ($localPath === false) {
            throw new \RuntimeException('无法创建临时文件，请检查服务器临时目录权限');
        }

        file_put_contents($localPath, $contents);

        return $localPath;
    }

    /**
     * ValidationException 的 getMessage() 固定是 "The given data was invalid."，
     * 对商户没有信息量，这里取第一条具体的字段错误（OrderShippingService::record()
     * 在订单状态不允许录入物流时抛的就是这个）。
     */
    private function errorMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return (string) (collect($e->errors())->flatten()->first() ?: $e->getMessage());
        }

        return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
    }

    private function truncate(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
}

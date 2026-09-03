<?php

namespace App\Services;

use App\Exceptions\BalanceOperationException;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\OrderDisputeEventReply;
use App\Models\User;
use App\Support\RichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 争议审核事件的业务编排：内容校验/XSS 过滤/图片转存 OSS/期限换算，
 * 资金冻结与释放委托给 BalanceService（见其 freezeForDisputeEvent()/
 * releaseForDisputeEvent()），保持"钱只能经 BalanceService 一处改动"的
 * 既有约定不被绕开。
 */
class OrderDisputeService
{
    private const ATTACHMENT_FEATURE = 'dispute-events';

    public function __construct(private readonly BalanceService $balanceService) {}

    /**
     * 开立争议审核事件（仅超级管理员/商户财务管理员）。
     *
     * @param  array  $data  表单原始输入：event_no, reason（富文本 HTML）,
     *                       images（本地临时盘相对路径数组，可选）, final_action,
     *                       deadline_value, deadline_unit
     *
     * @throws BalanceOperationException 订单状态不是 paid，或已存在处理中的事件
     */
    public function open(Order $order, User $operator, array $data): OrderDisputeEvent
    {
        $deadlineHours = $this->computeDeadlineHours((int) $data['deadline_value'], (string) $data['deadline_unit']);

        $attributes = [
            'event_no' => $data['event_no'],
            'reason' => RichTextSanitizer::sanitize((string) $data['reason']),
            'images' => $this->moveAttachments($data['images'] ?? [], $order->merchant_id),
            'final_action' => $data['final_action'],
            'deadline_value' => (int) $data['deadline_value'],
            'deadline_unit' => $data['deadline_unit'],
            'deadline_hours' => $deadlineHours,
        ];

        return $this->balanceService->freezeForDisputeEvent($order, $operator, $attributes);
    }

    /**
     * 回复争议审核事件（仅商户交易订单管理员），只在事件仍处理中时允许。
     * 事务内行锁重新校验状态，避免与手动/自动关闭发生竞态。
     *
     * @param  array  $data  表单原始输入：content（富文本 HTML）, images（可选）
     *
     * @throws BalanceOperationException 事件已不是处理中
     */
    public function reply(OrderDisputeEvent $event, User $operator, array $data): OrderDisputeEventReply
    {
        $content = RichTextSanitizer::sanitize((string) $data['content']);
        $images = $this->moveAttachments($data['images'] ?? [], $event->merchant_id);

        return DB::transaction(function () use ($event, $operator, $content, $images) {
            $fresh = OrderDisputeEvent::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($event->id);

            if (! $fresh->isProcessing()) {
                throw new BalanceOperationException('该争议审核事件已结束，无法回复。');
            }

            return OrderDisputeEventReply::query()->create([
                'order_dispute_event_id' => $fresh->id,
                'merchant_id' => $fresh->merchant_id,
                'content' => $content,
                'images' => $images,
                'operator_id' => $operator->id,
            ]);
        });
    }

    /**
     * 人工手动结束（仅超级管理员/商户财务管理员）。
     * 返回 false 表示事件已被（大概率是自动扫描）抢先关闭，不是错误。
     */
    public function closeManually(OrderDisputeEvent $event, User $operator, ?string $remark = null): bool
    {
        return $this->balanceService->releaseForDisputeEvent(
            $event,
            $operator,
            OrderDisputeEvent::CLOSE_TYPE_MANUAL,
            $remark,
        );
    }

    /**
     * 系统到期自动结束，供 order-disputes:close-due 定时任务调用。
     */
    public function autoClose(OrderDisputeEvent $event): bool
    {
        return $this->balanceService->releaseForDisputeEvent(
            $event,
            null,
            OrderDisputeEvent::CLOSE_TYPE_AUTO,
        );
    }

    /**
     * 到期前 24 小时提醒，供 order-disputes:send-reminders 定时任务调用。
     * 无论 Telegram 是否真正发出去（商户未配置机器人时 send() 返回 false，
     * 这不算失败），都置 reminded_at，避免每次调度重复发送。
     */
    public function sendDueReminder(OrderDisputeEvent $event, TelegramNotificationService $telegram): void
    {
        $message = __('admin.telegram_notification.order_dispute_due_soon', [
            'order_no' => $event->order_no,
            'event_no' => $event->event_no,
            'due_at' => $event->due_at->toDateTimeString(),
        ]);

        $telegram->send($event->merchant_id, $message);

        $event->update(['reminded_at' => now()]);
    }

    private function computeDeadlineHours(int $value, string $unit): int
    {
        return $unit === OrderDisputeEvent::DEADLINE_UNIT_DAYS ? $value * 24 : $value;
    }

    /**
     * 把 FileUpload 暂存在本地盘的文件转存到 OSS，返回 OSS 路径数组。
     * 路径约定同 ListOrders.php 里物流导入文件的转存方式：
     * merchants/{merchant_id}/{feature}/{date}/{filename}。
     *
     * @param  list<string>  $localTmpPaths  本地磁盘相对路径（FileUpload 提交值）
     * @return list<string> OSS 路径
     */
    private function moveAttachments(array $localTmpPaths, int $merchantId): array
    {
        $ossPaths = [];

        foreach ($localTmpPaths as $localTmpPath) {
            $ossPath = "merchants/{$merchantId}/".self::ATTACHMENT_FEATURE.'/'.now()->format('Y-m-d').'/'.basename($localTmpPath);

            Storage::disk('oss')->put($ossPath, Storage::disk('local')->get($localTmpPath));
            Storage::disk('local')->delete($localTmpPath);

            $ossPaths[] = $ossPath;
        }

        return $ossPaths;
    }
}

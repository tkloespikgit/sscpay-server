<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 付款链接落地页（4.4 节）。不需要 API 鉴权——访问控制完全依赖 token 本身
 * 的不可猜测性 + 订单状态必须是 pending + 未超过有效期
 * （Order::scopeValidPaymentLink()）。
 */
class PaymentPageController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $order = Order::query()
            ->withoutGlobalScopes()
            ->validPaymentLink()
            ->where('payment_link_token', $token)
            ->with(['items', 'merchant'])
            ->first();

        if (! $order) {
            return view('payment.expired');
        }

        return view('payment.show', ['order' => $order]);
    }

    /**
     * 客户在支付页选定支付方式后点击"确认支付"。
     *
     * 【需要你确认】文档 4.4 节写的是"调用现有支付确认接口"，暗示这一段
     * 已经有现成实现、不在本次需求范围内——这里只搭了骨架，实际跳转去哪个
     * 支付网关需要你们补上实际对接。订单变为 paid 不在这里发生：真正驱动
     * pending -> paid 流转的是插件异步回调 payment_status webhook（文档第一节，
     * 见 PaymentGatewayWebhookController -> OrderPaymentStatusService），
     * 那里已经接好了入账（BalanceService::creditForPaidOrder）、商户交易结果
     * 通知（OrderNotificationService::dispatchInitial）和 Telegram 通知
     * （OrderStatusChanged 事件），这个方法不需要重复做这些事。
     */
    public function confirm(Request $request, string $token): RedirectResponse
    {
        $order = Order::query()
            ->withoutGlobalScopes()
            ->validPaymentLink()
            ->where('payment_link_token', $token)
            ->first();

        if (! $order) {
            return redirect()->route('payment.expired');
        }

        $request->validate(['payment_method' => ['required', 'string']]);

        // TODO: 跳转到实际支付网关；网关回调成功后再把订单标记为 paid，
        // 并 event(new OrderStatusChanged($order, 'pending', 'paid'))。
        return redirect($order->return_url ?? route('payment.show', $token));
    }
}

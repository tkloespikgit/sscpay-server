<?php

namespace App\Http\Controllers;

use App\Models\CombinedOrder;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 付款链接落地页。访问控制依赖 token 不可猜测性 + pending + 未过期。
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

        if ($order->combined_order_id) {
            $parent = CombinedOrder::query()->forMerchant($order->merchant_id)->find($order->combined_order_id);

            return $parent ? redirect()->away($parent->pay_url) : view('payment.expired');
        }

        if ($order->pay_url) {
            $self = route('payment.show', ['token' => $token], absolute: true);
            if (rtrim((string) $order->pay_url, '/') !== rtrim($self, '/')) {
                return redirect()->away($order->pay_url);
            }
        }

        return view('payment.show', ['order' => $order]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $order = Order::query()
            ->withoutGlobalScopes()
            ->validPaymentLink()
            ->where('payment_link_token', $token)
            ->first();

        if (! $order) {
            return redirect()->route('payment.show', $token);
        }

        if ($order->combined_order_id) {
            return redirect()->route('payment.show', $token);
        }

        $request->validate(['payment_method' => ['required', 'string']]);

        return redirect($order->return_url ?? route('payment.show', $token));
    }
}

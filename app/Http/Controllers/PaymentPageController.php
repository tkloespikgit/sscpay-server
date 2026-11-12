<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 付款链接落地页。访问控制依赖 token 不可猜测性 + pending + 未过期。
 */
class PaymentPageController extends Controller
{
    public function show(Request $request, string $token, CryptoPaymentPageController $crypto): View|RedirectResponse|Response
    {
        if (Order::query()->withoutGlobalScopes()->where('payment_link_token', $token)->where('payment_type', 'crypto')->exists()) {
            return $crypto->show($request, $token);
        }
        $order = Order::query()
            ->withoutGlobalScopes()
            ->validPaymentLink()
            ->where('payment_type', 'fiat')
            ->where('payment_link_token', $token)
            ->with(['items', 'merchant'])
            ->first();

        if (! $order) {
            return view('payment.expired');
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
            ->where('payment_type', 'fiat')
            ->where('payment_link_token', $token)
            ->first();

        if (! $order) {
            return redirect()->route('payment.show', $token);
        }

        $request->validate(['payment_method' => ['required', 'string']]);

        return redirect($order->return_url ?? route('payment.show', $token));
    }
}
